<?php

// Audit log labels of inventory actions (inventory.*).
return [
    'unit_created' => 'Unit added',
    'unit_updated' => 'Unit changed',
    'category_created' => 'Category added',
    'category_updated' => 'Category changed',
    'warehouse_created' => 'Warehouse added',
    'warehouse_updated' => 'Warehouse changed',
    'item_created' => 'Item added',
    'item_updated' => 'Item changed',
    'document_created' => 'Stock document written',
    'document_updated' => 'Stock document changed',
    'document_deleted' => 'Stock document draft removed',
    'document_posted' => 'Stock document posted',
    'document_submitted' => 'Stock adjustment sent for approval',
    'document_rejected' => 'Stock adjustment sent back',
    'document_cancelled' => 'Stock document cancelled',
    'document_billed' => 'Supplier bill made from a goods receipt',
    'document_dispatched' => 'Transfer dispatched',
    'document_received' => 'Transfer received',
    'count_opened' => 'Stock count opened',
    'count_submitted' => 'Stock count sent for approval',
    'count_posted' => 'Stock count approved and posted',
    'count_rejected' => 'Stock count sent back',
    'count_cancelled' => 'Stock count cancelled',
];
