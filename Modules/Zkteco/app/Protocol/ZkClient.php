<?php

namespace Modules\Zkteco\Protocol;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Talks to a ZKTeco terminal (F18, K18 and their ZEM family) over its own protocol on
 * port 4370, UDP or TCP. Reading only: device facts, users and attendance
 * logs. It never writes to the terminal, never clears its logs and never
 * locks its keypad.
 *
 * A packet is command (u16), checksum (u16), session (u16), reply id (u16)
 * and data, little-endian; over TCP it is wrapped in a 8-byte header
 * (50 50 82 7d + length). Large answers come through a buffer read in chunks.
 */
class ZkClient
{
    public const CMD_CONNECT = 1000;

    public const CMD_EXIT = 1001;

    public const CMD_AUTH = 1102;

    public const CMD_OPTIONS_RRQ = 11;

    public const CMD_GET_FREE_SIZES = 50;

    public const CMD_ATTLOG_RRQ = 13;

    public const CMD_USERTEMP_RRQ = 9;

    public const CMD_PREPARE_DATA = 1500;

    public const CMD_DATA = 1501;

    public const CMD_FREE_DATA = 1502;

    public const CMD_DATA_WRRQ = 1503;

    public const CMD_READ_BUFFER = 1504;

    public const CMD_ACK_OK = 2000;

    public const CMD_ACK_ERROR = 2001;

    public const CMD_ACK_DATA = 2002;

    public const CMD_ACK_UNAUTH = 2005;

    private const FCT_USER = 5;

    private const USHRT_MAX = 65535;

    /** @var resource|null */
    private $socket = null;

    private int $session = 0;

    private int $replyId = 0;

    public function __construct(
        private string $host,
        private int $port = 4370,
        private string $protocol = 'udp',
        private int $commKey = 0,
        private int $timeout = 5,
    ) {}

    /** Open a session (with the comm key when the terminal asks for one). */
    public function connect(): void
    {
        $address = ($this->protocol === 'tcp' ? 'tcp' : 'udp')."://{$this->host}:{$this->port}";
        $socket = @stream_socket_client($address, $errno, $error, $this->timeout);
        if ($socket === false) {
            throw new RuntimeException("connect_failed: {$error}");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->session = 0;
        $this->replyId = self::USHRT_MAX - 1;

        [$code] = $this->command(self::CMD_CONNECT);
        if ($code === self::CMD_ACK_UNAUTH) {
            [$code] = $this->command(self::CMD_AUTH, self::commKeyBytes($this->commKey, $this->session));
        }
        if ($code !== self::CMD_ACK_OK) {
            $this->close();
            throw new RuntimeException($code === self::CMD_ACK_UNAUTH ? 'wrong_comm_key' : "connect_refused: {$code}");
        }
    }

    /** End the session politely and close the socket. */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            try {
                $this->command(self::CMD_EXIT);
            } catch (RuntimeException) {
                // Closing anyway.
            }
            $this->close();
        }
    }

    /** A device option, e.g. "~SerialNumber", "~DeviceName", "~Platform". */
    public function option(string $name): ?string
    {
        [$code, $data] = $this->command(self::CMD_OPTIONS_RRQ, $name."\0");
        if ($code !== self::CMD_ACK_OK) {
            return null;
        }
        $text = rtrim($data, "\0");
        $pos = strpos($text, '=');

        return $pos === false ? null : substr($text, $pos + 1);
    }

    /**
     * How many users and attendance logs the terminal holds.
     *
     * @return array{users: int, logs: int}
     */
    public function sizes(): array
    {
        [$code, $data] = $this->command(self::CMD_GET_FREE_SIZES);
        if ($code !== self::CMD_ACK_OK || strlen($data) < 80) {
            return ['users' => 0, 'logs' => 0];
        }
        $fields = array_values(unpack('V20', substr($data, 0, 80)));

        return ['users' => $fields[4], 'logs' => $fields[8]];
    }

    /**
     * The terminal's users: its internal number, the user id people type or
     * that the code on the card is, and the name.
     *
     * @return list<array{uid: int, user_id: string, name: string, card: int, privilege: int}>
     */
    public function users(): array
    {
        $count = $this->sizes()['users'];
        $data = $this->readBuffer(self::CMD_USERTEMP_RRQ, self::FCT_USER);
        if ($data === '' || $count === 0) {
            return [];
        }
        $size = unpack('V', substr($data, 0, 4))[1];
        $body = substr($data, 4, $size);
        $recordSize = intdiv(strlen($body), max(1, $count));
        $users = [];
        foreach (str_split($body, $recordSize) as $record) {
            if (strlen($record) < $recordSize) {
                continue;
            }
            if ($recordSize >= 72) {
                $row = unpack('vuid/Cprivilege/a8password/a24name/Vcard/x/a7group/x/a24user_id', $record);
                $users[] = ['uid' => $row['uid'], 'user_id' => self::text($row['user_id']) ?: (string) $row['uid'], 'name' => self::text($row['name']), 'card' => $row['card'], 'privilege' => $row['privilege']];
            } else {
                $row = unpack('vuid/Cprivilege/a5password/a8name/Vcard/x/Cgroup/vtimezone/Vuser_id', $record);
                $users[] = ['uid' => $row['uid'], 'user_id' => (string) $row['user_id'], 'name' => self::text($row['name']), 'card' => $row['card'], 'privilege' => $row['privilege']];
            }
        }

        return $users;
    }

    /**
     * The terminal's attendance logs: user id, the time written by its clock
     * (local, no zone), the verify state and the punch kind (0 in, 1 out…).
     *
     * @return list<array{uid: int, user_id: string, at: string, status: int, punch: int}>
     */
    public function attendance(): array
    {
        $count = $this->sizes()['logs'];
        $data = $this->readBuffer(self::CMD_ATTLOG_RRQ);
        if ($data === '' || $count === 0) {
            return [];
        }
        $size = unpack('V', substr($data, 0, 4))[1];
        $body = substr($data, 4, $size);
        $recordSize = intdiv(strlen($body), max(1, $count));
        $logs = [];
        foreach (str_split($body, max(1, $recordSize)) as $record) {
            if (strlen($record) < $recordSize) {
                continue;
            }
            if ($recordSize === 8) {
                $row = unpack('vuid/Cstatus/Vtime/Cpunch', $record);
                $userId = (string) $row['uid'];
            } elseif ($recordSize === 16) {
                $row = unpack('Vuser/Vtime/Cstatus/Cpunch', $record);
                $row['uid'] = $row['user'];
                $userId = (string) $row['user'];
            } else {
                $row = unpack('vuid/a24user_id/Cstatus/Vtime/Cpunch', $record);
                $userId = self::text($row['user_id']) ?: (string) $row['uid'];
            }
            $logs[] = ['uid' => $row['uid'], 'user_id' => $userId, 'at' => self::decodeTime($row['time']), 'status' => $row['status'], 'punch' => $row['punch']];
        }

        return $logs;
    }

    /** A terminal time (seconds packed into one number) as "Y-m-d H:i:s". */
    public static function decodeTime(int $t): string
    {
        $second = $t % 60;
        $t = intdiv($t, 60);
        $minute = $t % 60;
        $t = intdiv($t, 60);
        $hour = $t % 24;
        $t = intdiv($t, 24);
        $day = $t % 31 + 1;
        $t = intdiv($t, 31);
        $month = $t % 12 + 1;
        $year = intdiv($t, 12) + 2000;

        return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, 'UTC')->format('Y-m-d H:i:s');
    }

    /** The packet checksum (ones' complement of the 16-bit words). */
    public static function checksum(string $packet): int
    {
        $sum = 0;
        $length = strlen($packet);
        for ($i = 0; $i + 1 < $length; $i += 2) {
            $sum += unpack('v', substr($packet, $i, 2))[1];
            if ($sum > self::USHRT_MAX) {
                $sum -= self::USHRT_MAX;
            }
        }
        if ($length % 2 === 1) {
            $sum += ord($packet[$length - 1]);
            if ($sum > self::USHRT_MAX) {
                $sum -= self::USHRT_MAX;
            }
        }

        return ~$sum & 0xFFFF;
    }

    /** The scrambled comm key the AUTH command carries. */
    public static function commKeyBytes(int $key, int $session, int $ticks = 50): string
    {
        $k = 0;
        for ($i = 0; $i < 32; $i++) {
            $k = ($key & (1 << $i)) ? ($k << 1 | 1) : ($k << 1);
        }
        $k = ($k + $session) & 0xFFFFFFFF;
        $bytes = pack('V', $k);
        $bytes = chr(ord($bytes[0]) ^ ord('Z')).chr(ord($bytes[1]) ^ ord('K')).chr(ord($bytes[2]) ^ ord('S')).chr(ord($bytes[3]) ^ ord('O'));
        $halves = unpack('v2', $bytes);
        $bytes = pack('v2', $halves[2], $halves[1]);
        $b = $ticks & 0xFF;

        return chr(ord($bytes[0]) ^ $b).chr(ord($bytes[1]) ^ $b).chr($b).chr(ord($bytes[3]) ^ $b);
    }

    /**
     * Ask for a dataset through the buffer: the whole answer, or '' when the terminal has none.
     */
    private function readBuffer(int $command, int $fct = 0): string
    {
        [$code, $data] = $this->command(self::CMD_DATA_WRRQ, pack('CvVV', 1, $command, $fct, 0));
        if ($code === self::CMD_DATA) {
            return $data;
        }
        if ($code !== self::CMD_ACK_OK || strlen($data) < 5) {
            return '';
        }
        $size = unpack('V', substr($data, 1, 4))[1];
        $chunk = $this->protocol === 'tcp' ? 0xFFC0 : 16 * 1024;
        $out = '';
        for ($start = 0; $start < $size; $start += $chunk) {
            $out .= $this->readChunk($start, min($chunk, $size - $start));
        }
        $this->command(self::CMD_FREE_DATA);

        return $out;
    }

    /** One piece of the buffer: PREPARE_DATA, then DATA packets until ACK_OK. */
    private function readChunk(int $start, int $size): string
    {
        [$code, $data] = $this->command(self::CMD_READ_BUFFER, pack('VV', $start, $size));
        if ($code === self::CMD_DATA) {
            return $data;
        }
        if ($code !== self::CMD_PREPARE_DATA) {
            throw new RuntimeException("read_failed: {$code}");
        }
        $out = '';
        while (strlen($out) < $size) {
            [$next, $piece] = $this->receive();
            if ($next === self::CMD_DATA) {
                $out .= $piece;
            } elseif ($next === self::CMD_ACK_OK) {
                break;
            } else {
                throw new RuntimeException("read_failed: {$next}");
            }
        }
        // The closing ACK_OK after the last DATA (UDP sends it apart).
        if (strlen($out) >= $size && $this->protocol !== 'tcp') {
            $this->receive(optional: true);
        }

        return $out;
    }

    /**
     * Send a command and read its answer: [reply code, data].
     *
     * @return array{0: int, 1: string}
     */
    private function command(int $command, string $data = ''): array
    {
        if ($this->socket === null) {
            throw new RuntimeException('not_connected');
        }
        $this->replyId = ($this->replyId + 1) % self::USHRT_MAX;
        $packet = pack('vvvv', $command, 0, $this->session, $this->replyId).$data;
        $packet = pack('vvvv', $command, self::checksum($packet), $this->session, $this->replyId).$data;
        if ($this->protocol === 'tcp') {
            $packet = pack('vvV', 0x5050, 0x7D82, strlen($packet)).$packet;
        }
        fwrite($this->socket, $packet);
        [$code, $reply, $session] = $this->receive(withSession: true);
        if ($command === self::CMD_CONNECT) {
            $this->session = $session;
        }

        return [$code, $reply];
    }

    /**
     * Read one answer packet: [code, data, session].
     *
     * @return array{0: int, 1: string, 2: int}
     */
    private function receive(bool $withSession = false, bool $optional = false): array
    {
        if ($this->protocol === 'tcp') {
            $header = $this->readExactly(8, $optional);
            if ($header === '') {
                return [0, '', 0];
            }
            $length = unpack('V', substr($header, 4, 4))[1];
            $raw = $this->readExactly($length, false);
        } else {
            $raw = fread($this->socket, 65535 + 8);
            if ($raw === false || $raw === '') {
                if ($optional) {
                    return [0, '', 0];
                }
                throw new RuntimeException('no_answer');
            }
        }
        if (strlen($raw) < 8) {
            throw new RuntimeException('short_answer');
        }
        $head = unpack('vcode/vchecksum/vsession/vreply', substr($raw, 0, 8));

        return [$head['code'], substr($raw, 8), $head['session']];
    }

    private function readExactly(int $length, bool $optional): string
    {
        $out = '';
        while (strlen($out) < $length) {
            $piece = fread($this->socket, $length - strlen($out));
            if ($piece === false || $piece === '') {
                if ($optional && $out === '') {
                    return '';
                }
                throw new RuntimeException('no_answer');
            }
            $out .= $piece;
        }

        return $out;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private static function text(string $raw): string
    {
        $cut = strpos($raw, "\0");

        return trim($cut === false ? $raw : substr($raw, 0, $cut));
    }
}
