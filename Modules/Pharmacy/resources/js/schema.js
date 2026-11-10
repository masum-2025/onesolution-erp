/**
 * Pharmacy: what the shared generated screens show (made by make:erp-module).
 * Change fields here and in the module's migration, request and presenter together.
 */
export default {
    "key": "pharmacy",
    "slug": "pharmacy",
    "entities": [
        {
            "name": "generic",
            "path": "generics",
            "title": "name",
            "titleColumn": "name",
            "search": [
                "drug_class"
            ],
            "filters": [
                "is_controlled",
                "in_use"
            ],
            "columns": [
                "name",
                "drug_class",
                "is_controlled",
                "in_use"
            ],
            "fields": [
                {
                    "name": "name",
                    "column": "name",
                    "type": "translatable",
                    "required": true,
                    "max": 150
                },
                {
                    "name": "drug_class",
                    "column": "drug_class",
                    "type": "text",
                    "required": false,
                    "max": 60
                },
                {
                    "name": "is_controlled",
                    "column": "is_controlled",
                    "type": "boolean",
                    "required": false
                },
                {
                    "name": "controlled_schedule",
                    "column": "controlled_schedule",
                    "type": "text",
                    "required": false,
                    "max": 20
                },
                {
                    "name": "in_use",
                    "column": "in_use",
                    "type": "boolean",
                    "required": false
                }
            ]
        },
        {
            "name": "manufacturer",
            "path": "manufacturers",
            "title": "name",
            "titleColumn": "name",
            "search": [
                "country"
            ],
            "filters": [
                "in_use"
            ],
            "columns": [
                "name",
                "country",
                "in_use"
            ],
            "fields": [
                {
                    "name": "name",
                    "column": "name",
                    "type": "translatable",
                    "required": true,
                    "max": 150
                },
                {
                    "name": "country",
                    "column": "country",
                    "type": "text",
                    "required": false,
                    "max": 2
                },
                {
                    "name": "in_use",
                    "column": "in_use",
                    "type": "boolean",
                    "required": false
                }
            ]
        }
    ]
};
