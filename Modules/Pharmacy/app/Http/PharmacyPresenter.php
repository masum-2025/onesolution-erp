<?php

namespace Modules\Pharmacy\Http;

use Modules\Pharmacy\Models\Generic;
use Modules\Pharmacy\Models\ControlledEntry;
use Modules\Pharmacy\Models\Manufacturer;
use Modules\Pharmacy\Models\Medicine;
use Modules\Pharmacy\Models\Purchase;
use Modules\Pharmacy\Models\SaleLink;
use Modules\Pharmacy\Models\SupplierReturn;
use Modules\Pharmacy\Models\SupplierReturnLine;

/** The module's records as the screens read them (made by make:erp-module). */
class PharmacyPresenter
{
    /** @return array<string, mixed> */
    public function generic(Generic $record): array
    {
        return [
            'id' => $record->getKey(),
            'unit_id' => $record->unit_id,
            'name' => (object) $record->texts('name'),
            'drug_class' => $record->drug_class,
            'is_controlled' => $record->is_controlled,
            'controlled_schedule' => $record->controlled_schedule,
            'in_use' => $record->in_use,
            'version' => $record->version,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    /**
     * A medicine with its item, generic and maker (a row of Medicines::details()).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function medicine(array $row): array
    {
        /** @var Medicine $medicine */
        $medicine = $row['medicine'];
        $item = $row['item'] ?? [];

        return [
            'id' => $medicine->getKey(),
            'unit_id' => $medicine->unit_id,
            'item_id' => $medicine->item_id,
            'sku' => $item['sku'] ?? null,
            'barcode' => $item['barcode'] ?? null,
            'brand_name' => (object) ($item['names'] ?? []),
            'sale_price_minor' => $item['sale_price_minor'] ?? null,
            'tax_code_id' => $item['tax_code_id'] ?? null,
            'generic_id' => $medicine->generic_id,
            'generic' => (object) ($row['generic']?->texts('name') ?? []),
            'is_controlled' => (bool) $row['is_controlled'],
            'manufacturer_id' => $medicine->manufacturer_id,
            'manufacturer' => (object) ($row['manufacturer']?->texts('name') ?? []),
            'strength' => $medicine->strength,
            'form' => $medicine->form,
            'dgda_no' => $medicine->dgda_no,
            'mrp_minor' => $medicine->mrp_minor,
            'currency' => $medicine->currency,
            'pieces_per_strip' => $medicine->pieces_per_strip,
            'strips_per_box' => $medicine->strips_per_box,
            'requires_prescription' => $medicine->requires_prescription,
            'is_active' => $medicine->is_active,
            'version' => $medicine->version,
        ];
    }

    /** @return array<string, mixed> */
    public function sale(SaleLink $link, bool $withPrescription): array
    {
        return [
            'id' => $link->getKey(),
            'pos_sale_id' => $link->pos_sale_id,
            'number' => $link->sale_number,
            'total_minor' => $link->total_minor,
            'currency' => $link->currency,
            'sold_at' => $link->sold_at->toIso8601String(),
            'has_controlled' => $link->has_controlled,
            'from_prescription' => $link->consultation_id !== null,
            // The patient and prescriber are health data: only for those who keep the controlled register.
            'prescription' => $withPrescription ? [
                'patient_name' => $link->patient_name, 'prescriber_name' => $link->prescriber_name, 'prescriber_bmdc' => $link->prescriber_bmdc, 'ref' => $link->prescription_ref,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function purchase(Purchase $purchase): array
    {
        return [
            'id' => $purchase->getKey(),
            'document_id' => $purchase->document_id,
            'document_number' => $purchase->document_number,
            'warehouse_id' => $purchase->warehouse_id,
            'supplier' => $purchase->supplier,
            'reference' => $purchase->reference,
            'purchased_on' => $purchase->purchased_on->toDateString(),
            'total_minor' => $purchase->total_minor,
            'currency' => $purchase->currency,
            'line_count' => $purchase->line_count,
            'created_at' => $purchase->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<SupplierReturnLine>  $lines
     * @param  array<string, array<string, mixed>>  $medicines  keyed by id (Medicines::details())
     * @return array<string, mixed>
     */
    public function supplierReturn(SupplierReturn $return, iterable $lines, array $medicines, ?string $me): array
    {
        return [
            'id' => $return->getKey(),
            'number' => $return->number,
            'warehouse_id' => $return->warehouse_id,
            'supplier' => $return->supplier,
            'reason' => $return->reason,
            'status' => $return->status,
            'mine' => $me !== null && $return->created_by === $me,
            'decided_at' => $return->decided_at?->toIso8601String(),
            'created_at' => $return->created_at?->toIso8601String(),
            'version' => $return->version,
            'lines' => collect($lines)->map(fn (SupplierReturnLine $line) => [
                'medicine_id' => $line->medicine_id,
                'name' => (object) ($medicines[$line->medicine_id]['item']['names'] ?? []),
                'sku' => $medicines[$line->medicine_id]['item']['sku'] ?? null,
                'batch_number' => $line->batch_number,
                'expires_on' => $line->expires_on?->toDateString(),
                'quantity_milli' => $line->quantity_milli,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $medicines  keyed by id
     * @return array<string, mixed>
     */
    public function controlled(ControlledEntry $entry, array $medicines): array
    {
        return [
            'id' => $entry->getKey(),
            'medicine_id' => $entry->medicine_id,
            'name' => (object) ($medicines[$entry->medicine_id]['item']['names'] ?? []),
            'sku' => $medicines[$entry->medicine_id]['item']['sku'] ?? null,
            'batch_number' => $entry->batch_number,
            'direction' => $entry->direction,
            'quantity_milli' => $entry->quantity_milli,
            'balance_after_milli' => $entry->balance_after_milli,
            'source_type' => $entry->source_type,
            'patient_name' => $entry->patient_name,
            'prescriber_bmdc' => $entry->prescriber_bmdc,
            'note' => $entry->note,
            'recorded_at' => $entry->recorded_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function manufacturer(Manufacturer $record): array
    {
        return [
            'id' => $record->getKey(),
            'unit_id' => $record->unit_id,
            'name' => (object) $record->texts('name'),
            'country' => $record->country,
            'in_use' => $record->in_use,
            'version' => $record->version,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }
}
