/**
 * Diagnostics: what the shared generated screens show (made by make:erp-module).
 * Change fields here and in the module's migration, request and presenter together.
 */
export default {
    "key": "diagnostics",
    "slug": "diagnostics",
    "entities": [
        {
            "name": "test",
            "path": "tests",
            "title": "name",
            "titleColumn": "name",
            "search": [
                "code",
                "service_code"
            ],
            "filters": [
                "department",
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
                    "name": "department",
                    "column": "department",
                    "type": "enum",
                    "required": true,
                    "options": [
                        "haematology",
                        "biochemistry",
                        "microbiology",
                        "immunology",
                        "clinical_pathology",
                        "imaging",
                        "cardiology",
                        "other"
                    ]
                },
                {
                    "name": "sample_type",
                    "column": "sample_type",
                    "type": "enum",
                    "required": true,
                    "options": [
                        "blood",
                        "serum",
                        "urine",
                        "stool",
                        "swab",
                        "other",
                        "none"
                    ]
                },
                {
                    "name": "service_code",
                    "column": "service_code",
                    "type": "text",
                    "required": true,
                    "max": 30
                },
                {
                    "name": "turnaround_hours",
                    "column": "turnaround_hours",
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
        },
        {
            "name": "referrer",
            "path": "referrers",
            "title": "name",
            "titleColumn": "name",
            "search": [
                "name",
                "registration_no",
                "mobile"
            ],
            "filters": [
                "is_active"
            ],
            "columns": [
                "name",
                "registration_no"
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
                    "name": "registration_no",
                    "column": "registration_no",
                    "type": "text",
                    "required": false,
                    "max": 30
                },
                {
                    "name": "mobile",
                    "column": "mobile",
                    "type": "text",
                    "required": false,
                    "max": 20
                },
                {
                    "name": "commission_percent",
                    "column": "commission_percent",
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
