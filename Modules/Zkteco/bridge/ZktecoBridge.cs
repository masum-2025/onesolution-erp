// One Solutions ZKTeco office bridge (zkteco-bridge.exe).
//
// Runs on an office computer that sees the attendance devices: asks the app
// (online) which devices to read, reads each over the ZK protocol on the
// office network (reading only: nothing is written to a device, its logs
// stay), and sends what is new to the app over HTTPS with its bridge token.
//
// Built with the C# compiler of .NET Framework 4 that every Windows 10/11
// has (build.ps1), so nothing needs installing on the office computer.
// Written for that compiler: C# 5, no newer syntax.
//
//   zkteco-bridge.exe              read the devices once (Task Scheduler runs it every minute)
//   zkteco-bridge.exe --test       reach the app and each device, send nothing
//   zkteco-bridge.exe --install    run it every minute with Task Scheduler
//   zkteco-bridge.exe --uninstall  stop running it
//
// Settings: zkteco-bridge.ini beside the exe (server=…, token=…). The log
// (zkteco-bridge.log beside it) never holds the token.

using System;
using System.Collections;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Reflection;
using System.Text;
using System.Threading;
using System.Web.Script.Serialization;

[assembly: AssemblyTitle("One Solutions ZKTeco bridge")]
[assembly: AssemblyProduct("One Solutions ERP")]
[assembly: AssemblyVersion("1.0.0.0")]

namespace OneSolutions.ZktecoBridge
{
    public static class Program
    {
        const string TaskName = "OneSolutions ZKTeco Bridge";
        static string folder;
        static string logFile;

        public static int Main(string[] args)
        {
            folder = Path.GetDirectoryName(Assembly.GetExecutingAssembly().Location);
            logFile = Path.Combine(folder, "zkteco-bridge.log");
            string mode = args.Length > 0 ? args[0].ToLowerInvariant() : "";
            try
            {
                if (mode == "--install") return Install();
                if (mode == "--uninstall") return Uninstall();
                if (mode == "--help" || mode == "/?") { Console.WriteLine("zkteco-bridge.exe [--test | --install | --uninstall]"); return 0; }

                bool created;
                using (Mutex mutex = new Mutex(true, "Global\\OneSolutionsZktecoBridge", out created))
                {
                    if (!created) { Log("another run is still going; this one stops"); return 0; }
                    return Run(mode == "--test");
                }
            }
            catch (Exception exception)
            {
                Log("stopped: " + exception.Message);
                return 1;
            }
        }

        static int Run(bool testOnly)
        {
            Dictionary<string, string> settings = ReadSettings();
            Api api = new Api(settings["server"], settings["token"]);
            string onlyUser = settings.ContainsKey("only_user") ? settings["only_user"] : null;

            Dictionary<string, object> answer = api.Get("/api/zkteco/bridge/devices");
            ArrayList devices = (ArrayList)answer["data"];
            int maxLogs = 5000;
            Dictionary<string, object> meta = answer.ContainsKey("meta") ? answer["meta"] as Dictionary<string, object> : null;
            if (meta != null && meta.ContainsKey("max_logs")) maxLogs = Convert.ToInt32(meta["max_logs"]);
            Log((testOnly ? "test: " : "") + "app answered; devices to read: " + devices.Count);

            int failed = 0;
            foreach (object item in devices)
            {
                Dictionary<string, object> device = (Dictionary<string, object>)item;
                string id = (string)device["id"];
                string name = (string)device["name"];
                ZkClient zk = new ZkClient((string)device["host"], Convert.ToInt32(device["port"]), (string)device["protocol"], Convert.ToInt32(device["comm_key"]), 5000);
                try
                {
                    zk.Connect();
                    Dictionary<string, object> facts = zk.Facts();
                    if (testOnly)
                    {
                        Log("test: " + name + " answered: " + facts["model"] + ", " + facts["users"] + " users, " + facts["logs"] + " logs");
                        continue;
                    }
                    List<Dictionary<string, object>> users = zk.Users();
                    List<Dictionary<string, object>> logs = zk.Attendance();
                    zk.Disconnect();

                    string since = (string)device["since"];
                    string from = (string)device["import_from"] + " 00:00:00";
                    List<Dictionary<string, object>> fresh = new List<Dictionary<string, object>>();
                    foreach (Dictionary<string, object> log in logs)
                    {
                        string at = (string)log["at"];
                        // The app keeps each log once: sending the last second again is harmless.
                        if (string.CompareOrdinal(at, since) < 0 || string.CompareOrdinal(at, from) < 0) continue;
                        if (onlyUser != null && (string)log["user_id"] != onlyUser) continue;
                        fresh.Add(log);
                    }
                    int sent = 0;
                    int recorded = 0;
                    for (int start = 0; start == 0 || start < fresh.Count; start += maxLogs)
                    {
                        Dictionary<string, object> body = new Dictionary<string, object>();
                        body["facts"] = start == 0 ? facts : null;
                        body["users"] = start == 0 ? (object)users : new ArrayList();
                        body["logs"] = fresh.GetRange(start, Math.Min(maxLogs, Math.Max(0, fresh.Count - start)));
                        Dictionary<string, object> result = (Dictionary<string, object>)api.Post("/api/zkteco/bridge/devices/" + id + "/readings", body)["data"];
                        sent += Convert.ToInt32(result["new"]);
                        recorded += Convert.ToInt32(result["recorded"]);
                        if (fresh.Count == 0) break;
                    }
                    Log(name + ": read " + logs.Count + ", sent " + fresh.Count + ", new " + sent + ", into attendance " + recorded);
                }
                catch (ZkException exception)
                {
                    failed++;
                    Log(name + ": could not be read (" + exception.Reason + ")");
                    if (!testOnly)
                    {
                        Dictionary<string, object> body = new Dictionary<string, object>();
                        body["reason"] = exception.Reason;
                        try { api.Post("/api/zkteco/bridge/devices/" + id + "/error", body); } catch (Exception) { }
                    }
                }
                finally
                {
                    zk.Disconnect();
                }
            }

            return failed == 0 ? 0 : 2;
        }

        static Dictionary<string, string> ReadSettings()
        {
            string file = Path.Combine(folder, "zkteco-bridge.ini");
            if (!File.Exists(file)) throw new Exception("zkteco-bridge.ini is missing beside the exe; download it from the app (Attendance devices > Office bridges)");
            Dictionary<string, string> settings = new Dictionary<string, string>();
            foreach (string raw in File.ReadAllLines(file))
            {
                string line = raw.Trim();
                if (line.Length == 0 || line.StartsWith("#") || line.StartsWith(";") || line.StartsWith("[")) continue;
                int equals = line.IndexOf('=');
                if (equals > 0) settings[line.Substring(0, equals).Trim().ToLowerInvariant()] = line.Substring(equals + 1).Trim();
            }
            if (!settings.ContainsKey("server") || !settings.ContainsKey("token") || settings["token"].Length < 20)
                throw new Exception("zkteco-bridge.ini needs server= and token=");
            return settings;
        }

        static int Install()
        {
            string exe = Assembly.GetExecutingAssembly().Location;
            int code = Shell("schtasks.exe", "/Create /F /SC MINUTE /MO 1 /TN \"" + TaskName + "\" /TR \"\\\"" + exe + "\\\"\" /RL LIMITED");
            Console.WriteLine(code == 0 ? "Installed: it runs every minute (Task Scheduler: " + TaskName + ")." : "Could not install; run this window as administrator and try again.");
            Log(code == 0 ? "installed in Task Scheduler" : "install failed (" + code + ")");
            return code;
        }

        static int Uninstall()
        {
            int code = Shell("schtasks.exe", "/Delete /F /TN \"" + TaskName + "\"");
            Console.WriteLine(code == 0 ? "Removed from Task Scheduler." : "It was not installed.");
            Log("uninstalled (" + code + ")");
            return code;
        }

        static int Shell(string file, string arguments)
        {
            ProcessStartInfo start = new ProcessStartInfo(file, arguments);
            start.UseShellExecute = false;
            start.CreateNoWindow = true;
            using (Process process = Process.Start(start))
            {
                process.WaitForExit();
                return process.ExitCode;
            }
        }

        public static void Log(string text)
        {
            string line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "  " + text;
            Console.WriteLine(line);
            try
            {
                if (File.Exists(logFile) && new FileInfo(logFile).Length > 1024 * 1024)
                {
                    File.Copy(logFile, logFile + ".old", true);
                    File.Delete(logFile);
                }
                File.AppendAllText(logFile, line + Environment.NewLine);
            }
            catch (Exception) { }
        }
    }

    /// <summary>The app's bridge API over HTTPS, with the token in the Authorization header.</summary>
    public class Api
    {
        readonly string server;
        readonly string token;
        readonly JavaScriptSerializer json = new JavaScriptSerializer();

        public Api(string server, string token)
        {
            this.server = server.TrimEnd('/');
            this.token = token;
            json.MaxJsonLength = int.MaxValue;
            // TLS 1.2 on .NET Framework 4 (the constant has no name there).
            ServicePointManager.SecurityProtocol = (SecurityProtocolType)3072;
        }

        public Dictionary<string, object> Get(string path) { return Send("GET", path, null); }

        public Dictionary<string, object> Post(string path, object body) { return Send("POST", path, body); }

        Dictionary<string, object> Send(string method, string path, object body)
        {
            HttpWebRequest request = (HttpWebRequest)WebRequest.Create(server + path);
            request.Method = method;
            request.Accept = "application/json";
            request.Headers["Authorization"] = "Bearer " + token;
            request.Timeout = 120000;
            request.UserAgent = "OneSolutions-ZktecoBridge/1.0";
            if (body != null)
            {
                byte[] bytes = Encoding.UTF8.GetBytes(json.Serialize(body));
                request.ContentType = "application/json";
                request.ContentLength = bytes.Length;
                using (Stream stream = request.GetRequestStream()) stream.Write(bytes, 0, bytes.Length);
            }
            try
            {
                using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
                    return Read(response);
            }
            catch (WebException exception)
            {
                HttpWebResponse response = exception.Response as HttpWebResponse;
                if (response == null) throw new Exception("the app could not be reached (" + exception.Status + "); check the internet and server= in zkteco-bridge.ini");
                Dictionary<string, object> error = Read(response);
                string message = error != null && error.ContainsKey("message") ? Convert.ToString(error["message"]) : "";
                throw new Exception("the app answered " + (int)response.StatusCode + ": " + message);
            }
        }

        Dictionary<string, object> Read(HttpWebResponse response)
        {
            using (StreamReader reader = new StreamReader(response.GetResponseStream(), Encoding.UTF8))
            {
                string text = reader.ReadToEnd();
                return text.Length == 0 ? new Dictionary<string, object>() : json.Deserialize<Dictionary<string, object>>(text);
            }
        }
    }

    public class ZkException : Exception
    {
        public readonly string Reason;

        public ZkException(string reason) : base(reason) { Reason = reason; }
    }

    /// <summary>
    /// The ZK protocol on port 4370 (UDP or TCP), reading only: connect (with the
    /// comm key when asked), device facts, users, attendance logs, exit. The same
    /// as the app's Modules/Zkteco/app/Protocol/ZkClient.php.
    /// </summary>
    public class ZkClient
    {
        const int CMD_CONNECT = 1000, CMD_EXIT = 1001, CMD_AUTH = 1102, CMD_OPTIONS_RRQ = 11, CMD_GET_FREE_SIZES = 50;
        const int CMD_ATTLOG_RRQ = 13, CMD_USERTEMP_RRQ = 9, CMD_PREPARE_DATA = 1500, CMD_DATA = 1501, CMD_FREE_DATA = 1502;
        const int CMD_DATA_WRRQ = 1503, CMD_READ_BUFFER = 1504, CMD_ACK_OK = 2000, CMD_ACK_UNAUTH = 2005, FCT_USER = 5;
        const int USHRT_MAX = 65535;

        readonly string host;
        readonly int port;
        readonly bool tcp;
        readonly int commKey;
        readonly int timeout;
        UdpClient udp;
        TcpClient tcpClient;
        NetworkStream stream;
        int session;
        int replyId;

        public ZkClient(string host, int port, string protocol, int commKey, int timeoutMs)
        {
            this.host = host;
            this.port = port;
            this.tcp = protocol == "tcp";
            this.commKey = commKey;
            this.timeout = timeoutMs;
        }

        public void Connect()
        {
            try
            {
                if (tcp)
                {
                    tcpClient = new TcpClient();
                    IAsyncResult attempt = tcpClient.BeginConnect(host, port, null, null);
                    if (!attempt.AsyncWaitHandle.WaitOne(timeout)) throw new ZkException("no_answer");
                    tcpClient.EndConnect(attempt);
                    tcpClient.ReceiveTimeout = timeout;
                    stream = tcpClient.GetStream();
                }
                else
                {
                    udp = new UdpClient();
                    udp.Client.ReceiveTimeout = timeout;
                    udp.Connect(host, port);
                }
            }
            catch (SocketException) { throw new ZkException("no_answer"); }
            session = 0;
            replyId = USHRT_MAX - 1;
            int code = Command(CMD_CONNECT, new byte[0]).Code;
            if (code == CMD_ACK_UNAUTH) code = Command(CMD_AUTH, CommKeyBytes(commKey, session)).Code;
            if (code != CMD_ACK_OK)
            {
                Close();
                throw new ZkException(code == CMD_ACK_UNAUTH ? "wrong_comm_key" : "refused");
            }
        }

        public void Disconnect()
        {
            if (udp == null && stream == null) return;
            try { Command(CMD_EXIT, new byte[0]); } catch (Exception) { }
            Close();
        }

        public Dictionary<string, object> Facts()
        {
            int[] sizes = Sizes();
            Dictionary<string, object> facts = new Dictionary<string, object>();
            facts["serial"] = Option("~SerialNumber");
            facts["model"] = Option("~DeviceName");
            facts["users"] = sizes[0];
            facts["logs"] = sizes[1];
            return facts;
        }

        public List<Dictionary<string, object>> Users()
        {
            int count = Sizes()[0];
            List<Dictionary<string, object>> users = new List<Dictionary<string, object>>();
            byte[] data = ReadBuffer(CMD_USERTEMP_RRQ, FCT_USER);
            if (data.Length < 4 || count == 0) return users;
            int size = BitConverter.ToInt32(data, 0);
            int length = Math.Min(size, data.Length - 4);
            int recordSize = length / count;
            for (int offset = 4; offset + recordSize <= 4 + length; offset += recordSize)
            {
                Dictionary<string, object> user = new Dictionary<string, object>();
                int uid = BitConverter.ToUInt16(data, offset);
                user["uid"] = uid;
                if (recordSize >= 72)
                {
                    string userId = Text(data, offset + 48, 24);
                    user["user_id"] = userId.Length > 0 ? userId : uid.ToString();
                    user["name"] = Text(data, offset + 11, 24);
                }
                else
                {
                    user["user_id"] = BitConverter.ToUInt32(data, offset + 24).ToString();
                    user["name"] = Text(data, offset + 8, 8);
                }
                users.Add(user);
            }
            return users;
        }

        public List<Dictionary<string, object>> Attendance()
        {
            int count = Sizes()[1];
            List<Dictionary<string, object>> logs = new List<Dictionary<string, object>>();
            byte[] data = ReadBuffer(CMD_ATTLOG_RRQ, 0);
            if (data.Length < 4 || count == 0) return logs;
            int size = BitConverter.ToInt32(data, 0);
            int length = Math.Min(size, data.Length - 4);
            int recordSize = Math.Max(1, length / count);
            for (int offset = 4; offset + recordSize <= 4 + length; offset += recordSize)
            {
                Dictionary<string, object> log = new Dictionary<string, object>();
                if (recordSize == 8)
                {
                    log["user_id"] = BitConverter.ToUInt16(data, offset).ToString();
                    log["status"] = (int)data[offset + 2];
                    log["at"] = DecodeTime(BitConverter.ToUInt32(data, offset + 3));
                    log["punch"] = (int)data[offset + 7];
                }
                else if (recordSize == 16)
                {
                    log["user_id"] = BitConverter.ToUInt32(data, offset).ToString();
                    log["at"] = DecodeTime(BitConverter.ToUInt32(data, offset + 4));
                    log["status"] = (int)data[offset + 8];
                    log["punch"] = (int)data[offset + 9];
                }
                else
                {
                    string userId = Text(data, offset + 2, 24);
                    log["user_id"] = userId.Length > 0 ? userId : BitConverter.ToUInt16(data, offset).ToString();
                    log["status"] = (int)data[offset + 26];
                    log["at"] = DecodeTime(BitConverter.ToUInt32(data, offset + 27));
                    log["punch"] = (int)data[offset + 31];
                }
                logs.Add(log);
            }
            return logs;
        }

        public static string DecodeTime(uint t)
        {
            uint second = t % 60; t /= 60;
            uint minute = t % 60; t /= 60;
            uint hour = t % 24; t /= 24;
            uint day = t % 31 + 1; t /= 31;
            uint month = t % 12 + 1; t /= 12;
            uint year = t + 2000;
            return string.Format("{0:0000}-{1:00}-{2:00} {3:00}:{4:00}:{5:00}", year, month, day, hour, minute, second);
        }

        string Option(string name)
        {
            Reply reply = Command(CMD_OPTIONS_RRQ, Encoding.ASCII.GetBytes(name + "\0"));
            if (reply.Code != CMD_ACK_OK) return null;
            string text = Encoding.ASCII.GetString(reply.Data).TrimEnd('\0');
            int equals = text.IndexOf('=');
            return equals < 0 ? null : text.Substring(equals + 1);
        }

        int[] Sizes()
        {
            Reply reply = Command(CMD_GET_FREE_SIZES, new byte[0]);
            if (reply.Code != CMD_ACK_OK || reply.Data.Length < 80) return new int[] { 0, 0 };
            return new int[] { BitConverter.ToInt32(reply.Data, 16), BitConverter.ToInt32(reply.Data, 32) };
        }

        byte[] ReadBuffer(int command, int fct)
        {
            byte[] ask = new byte[11];
            ask[0] = 1;
            BitConverter.GetBytes((ushort)command).CopyTo(ask, 1);
            BitConverter.GetBytes(fct).CopyTo(ask, 3);
            Reply reply = Command(CMD_DATA_WRRQ, ask);
            if (reply.Code == CMD_DATA) return reply.Data;
            if (reply.Code != CMD_ACK_OK || reply.Data.Length < 5) return new byte[0];
            int size = BitConverter.ToInt32(reply.Data, 1);
            int chunk = tcp ? 0xFFC0 : 16 * 1024;
            MemoryStream all = new MemoryStream();
            for (int start = 0; start < size; start += chunk)
            {
                byte[] part = ReadChunk(start, Math.Min(chunk, size - start));
                all.Write(part, 0, part.Length);
            }
            Command(CMD_FREE_DATA, new byte[0]);
            return all.ToArray();
        }

        byte[] ReadChunk(int start, int size)
        {
            byte[] ask = new byte[8];
            BitConverter.GetBytes(start).CopyTo(ask, 0);
            BitConverter.GetBytes(size).CopyTo(ask, 4);
            Reply reply = Command(CMD_READ_BUFFER, ask);
            if (reply.Code == CMD_DATA) return reply.Data;
            if (reply.Code != CMD_PREPARE_DATA) throw new ZkException("read_failed");
            MemoryStream part = new MemoryStream();
            while (part.Length < size)
            {
                Reply next = Receive(false);
                if (next.Code == CMD_DATA) part.Write(next.Data, 0, next.Data.Length);
                else if (next.Code == CMD_ACK_OK) break;
                else throw new ZkException("read_failed");
            }
            // The closing ACK_OK after the last DATA (UDP sends it apart).
            if (part.Length >= size && !tcp) Receive(true);
            return part.ToArray();
        }

        Reply Command(int command, byte[] data)
        {
            replyId = (replyId + 1) % USHRT_MAX;
            byte[] packet = new byte[8 + data.Length];
            BitConverter.GetBytes((ushort)command).CopyTo(packet, 0);
            BitConverter.GetBytes((ushort)session).CopyTo(packet, 4);
            BitConverter.GetBytes((ushort)replyId).CopyTo(packet, 6);
            data.CopyTo(packet, 8);
            BitConverter.GetBytes((ushort)Checksum(packet)).CopyTo(packet, 2);
            try
            {
                if (tcp)
                {
                    byte[] framed = new byte[8 + packet.Length];
                    framed[0] = 0x50; framed[1] = 0x50; framed[2] = 0x82; framed[3] = 0x7D;
                    BitConverter.GetBytes(packet.Length).CopyTo(framed, 4);
                    packet.CopyTo(framed, 8);
                    stream.Write(framed, 0, framed.Length);
                }
                else
                {
                    udp.Send(packet, packet.Length);
                }
            }
            catch (Exception) { throw new ZkException("no_answer"); }
            Reply reply = Receive(false);
            if (command == CMD_CONNECT) session = reply.Session;
            return reply;
        }

        Reply Receive(bool optional)
        {
            byte[] raw;
            try
            {
                if (tcp)
                {
                    byte[] header = ReadExactly(8);
                    raw = ReadExactly(BitConverter.ToInt32(header, 4));
                }
                else
                {
                    IPEndPoint from = new IPEndPoint(IPAddress.Any, 0);
                    raw = udp.Receive(ref from);
                }
            }
            catch (Exception)
            {
                if (optional) return new Reply(0, new byte[0], 0);
                throw new ZkException("no_answer");
            }
            if (raw.Length < 8) throw new ZkException("read_failed");
            byte[] data = new byte[raw.Length - 8];
            Array.Copy(raw, 8, data, 0, data.Length);
            return new Reply(BitConverter.ToUInt16(raw, 0), data, BitConverter.ToUInt16(raw, 4));
        }

        byte[] ReadExactly(int length)
        {
            byte[] buffer = new byte[length];
            int got = 0;
            while (got < length)
            {
                int read = stream.Read(buffer, got, length - got);
                if (read <= 0) throw new ZkException("no_answer");
                got += read;
            }
            return buffer;
        }

        void Close()
        {
            if (udp != null) { udp.Close(); udp = null; }
            if (stream != null) { stream.Close(); stream = null; }
            if (tcpClient != null) { tcpClient.Close(); tcpClient = null; }
        }

        public static int Checksum(byte[] packet)
        {
            int sum = 0;
            int i = 0;
            for (; i + 1 < packet.Length; i += 2)
            {
                sum += BitConverter.ToUInt16(packet, i);
                if (sum > USHRT_MAX) sum -= USHRT_MAX;
            }
            if (packet.Length % 2 == 1)
            {
                sum += packet[packet.Length - 1];
                if (sum > USHRT_MAX) sum -= USHRT_MAX;
            }
            return ~sum & 0xFFFF;
        }

        static byte[] CommKeyBytes(int key, int sessionId)
        {
            uint k = 0;
            for (int i = 0; i < 32; i++) k = ((key & (1 << i)) != 0) ? (k << 1 | 1) : (k << 1);
            k = unchecked(k + (uint)sessionId);
            byte[] b = BitConverter.GetBytes(k);
            b[0] ^= (byte)'Z'; b[1] ^= (byte)'K'; b[2] ^= (byte)'S'; b[3] ^= (byte)'O';
            byte[] swapped = new byte[] { b[2], b[3], b[0], b[1] };
            byte ticks = 50;
            return new byte[] { (byte)(swapped[0] ^ ticks), (byte)(swapped[1] ^ ticks), ticks, (byte)(swapped[3] ^ ticks) };
        }

        static string Text(byte[] data, int offset, int length)
        {
            int end = offset;
            while (end < offset + length && end < data.Length && data[end] != 0) end++;
            return Encoding.UTF8.GetString(data, offset, end - offset).Trim();
        }

        class Reply
        {
            public readonly int Code;
            public readonly byte[] Data;
            public readonly int Session;

            public Reply(int code, byte[] data, int session) { Code = code; Data = data; Session = session; }
        }
    }
}
