/**
 * Outpatient: what the shared generated screens show (made by make:erp-module).
 * Change fields here and in the module's migration, request and presenter together.
 */
export default {
    "key": "outpatient",
    "slug": "outpatient",
    "entities": [
        {
            "name": "doctor",
            "path": "doctors",
            "title": "name",
            "titleColumn": "name",
            "search": [
                "registration_no",
                "specialty",
                "mobile"
            ],
            "filters": [
                "is_active"
            ],
            "columns": [
                "registration_no",
                "specialty"
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
                    "name": "registration_no",
                    "column": "registration_no",
                    "type": "text",
                    "required": false,
                    "max": 30
                },
                {
                    "name": "degrees",
                    "column": "degrees",
                    "type": "text",
                    "required": false,
                    "max": 200
                },
                {
                    "name": "specialty",
                    "column": "specialty",
                    "type": "text",
                    "required": false,
                    "max": 150
                },
                {
                    "name": "mobile",
                    "column": "mobile",
                    "type": "text",
                    "required": false,
                    "max": 20
                },
                {
                    "name": "fee_service_code",
                    "column": "fee_service_code",
                    "type": "text",
                    "required": true,
                    "max": 30
                },
                {
                    "name": "follow_up_service_code",
                    "column": "follow_up_service_code",
                    "type": "text",
                    "required": false,
                    "max": 30
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
            "name": "chamber",
            "path": "chambers",
            "title": "name",
            "titleColumn": "name",
            "search": [
                "room"
            ],
            "filters": [
                "is_active"
            ],
            "columns": [
                "room"
            ],
            "fields": [
                {
                    "name": "name",
                    "column": "name",
                    "type": "text",
                    "required": true,
                    "max": 100
                },
                {
                    "name": "room",
                    "column": "room",
                    "type": "text",
                    "required": false,
                    "max": 30
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
