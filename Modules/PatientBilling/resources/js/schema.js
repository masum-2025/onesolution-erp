/**
 * Patient billing: what the shared generated screens show (made by make:erp-module).
 * Change fields here and in the module's migration, request and presenter together.
 */
export default {
    "key": "patient_billing",
    "slug": "patient-billing",
    "entities": [
        {
            "name": "service",
            "path": "services",
            "title": "name",
            "titleColumn": "name",
            "search": [
                "code"
            ],
            "filters": [
                "category",
                "is_active"
            ],
            "columns": [
                "code",
                "name"
            ],
            "fields": [
                {
                    "name": "code",
                    "column": "code",
                    "type": "text",
                    "required": true,
                    "max": 30
                },
                {
                    "name": "name",
                    "column": "name",
                    "type": "translatable",
                    "required": true,
                    "max": 150
                },
                {
                    "name": "category",
                    "column": "category",
                    "type": "enum",
                    "required": true,
                    "options": [
                        "consultation",
                        "test",
                        "bed",
                        "procedure",
                        "other"
                    ]
                },
                {
                    "name": "price",
                    "column": "price_minor",
                    "type": "money",
                    "required": true
                },
                {
                    "name": "tax_code_id",
                    "column": "tax_code_id",
                    "type": "belongs_to",
                    "entity": "tax_code",
                    "required": false
                },
                {
                    "name": "is_active",
                    "column": "is_active",
                    "type": "boolean",
                    "required": false
                }
            ]
        },
        {
            "name": "panel",
            "path": "panels",
            "title": "name",
            "titleColumn": "name",
            "search": [
                "name"
            ],
            "filters": [
                "kind",
                "is_active"
            ],
            "columns": [
                "name"
            ],
            "fields": [
                {
                    "name": "name",
                    "column": "name",
                    "type": "text",
                    "required": true,
                    "max": 150
                },
                {
                    "name": "kind",
                    "column": "kind",
                    "type": "enum",
                    "required": true,
                    "options": [
                        "corporate",
                        "panel"
                    ]
                },
                {
                    "name": "credit_limit",
                    "column": "credit_limit_minor",
                    "type": "money",
                    "required": false
                },
                {
                    "name": "discount_percent",
                    "column": "discount_percent",
                    "type": "integer",
                    "required": false
                },
                {
                    "name": "is_active",
                    "column": "is_active",
                    "type": "boolean",
                    "required": false
                }
            ]
        }
    ]
};
