<?php

namespace Modules\FactoryErp\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\OrderLine;
use Modules\FactoryErp\Models\OutputLine;
use Modules\FactoryErp\Models\ReceiptLine;

/**
 * Where an order's pieces are, per size and colour: ordered, cut (lays
 * not voided), sewn good and rejected (output not voided), and received
 * into the store as finished goods. Each stage is limited by the one
 * before it: good + rejects never above cut, received never above good.
 */
class Progress
{
    public function __construct(private Floor $floor, private Cuttings $cuttings) {}

    /**
     * @return array<string, array{ordered: int, cut: int, good: int, rejects: int, received: int}> keyed "size|colour"
     */
    public function of(Organization $company, Order $order): array
    {
        $cells = [];
        $add = function (string $key, string $stage, int $value) use (&$cells) {
            $cells[$key] ??= ['ordered' => 0, 'cut' => 0, 'good' => 0, 'rejects' => 0, 'received' => 0];
            $cells[$key][$stage] += $value;
        };
        foreach ($this->floor->query(OrderLine::class, $company)->where('order_id', $order->getKey())->get() as $line) {
            $add("{$line->size}|{$line->color}", 'ordered', $line->quantity);
        }
        foreach ($this->cuttings->cut($company, $order) as $key => $quantity) {
            $add($key, 'cut', $quantity);
        }
        $sewn = $this->floor->query(OutputLine::class, $company)->where('fac_output_lines.order_id', $order->getKey())
            ->join('fac_outputs', 'fac_outputs.id', '=', 'fac_output_lines.output_id')->whereNull('fac_outputs.voided_at')
            ->get(['fac_output_lines.size', 'fac_output_lines.color', 'fac_output_lines.good', 'fac_output_lines.rejects']);
        foreach ($sewn as $line) {
            $add("{$line->size}|{$line->color}", 'good', $line->good);
            $add("{$line->size}|{$line->color}", 'rejects', $line->rejects);
        }
        foreach ($this->floor->query(ReceiptLine::class, $company)->where('order_id', $order->getKey())->get() as $line) {
            $add("{$line->size}|{$line->color}", 'received', $line->quantity);
        }

        return $cells;
    }

    /**
     * Totals of every stage.
     *
     * @param  array<string, array<string, int>>  $cells
     * @return array{ordered: int, cut: int, good: int, rejects: int, received: int}
     */
    public static function totals(array $cells): array
    {
        $totals = ['ordered' => 0, 'cut' => 0, 'good' => 0, 'rejects' => 0, 'received' => 0];
        foreach ($cells as $cell) {
            foreach ($totals as $stage => $sum) {
                $totals[$stage] = $sum + $cell[$stage];
            }
        }

        return $totals;
    }
}
