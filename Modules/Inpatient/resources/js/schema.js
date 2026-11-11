/**
 * Inpatient: what the shared generated screens show (made by make:erp-module).
 * Change fields here and in the module's migration, request and presenter together.
 */
export default {
    "key": "inpatient",
    "slug": "inpatient",
    "entities": [
        {
            "name": "ward",
            "path": "wards",
            "title": "name",
            "titleColumn": "name",
            "search": [],
            "filters": [
                "kind",
                "is_active"
            ],
            "columns": [],
            "fields": [
                {
                    "name": "name",
                    "column": "name",
                    "type": "translatable",
                    "required": true,
                    "max": 100
                },
                {
                    "name": "kind",
                    "column": "kind",
                    "type": "enum",
                    "required": true,
                    "options": [
                        "general",
                        "cabin",
                        "icu",
                        "hdu",
                        "nicu",
                        "other"
                    ]
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
