/**
 * Fleet: what the shared generated screens show (made by make:erp-module).
 * Change fields here and in the module's migration, request and presenter together.
 */
export default {
    "key": "fleet",
    "slug": "fleet",
    "entities": [
        {
            "name": "vehicle",
            "path": "vehicles",
            "title": "registration_no",
            "titleColumn": "registration_no",
            "search": [
                "registration_no",
                "model"
            ],
            "filters": [
                "kind",
                "in_use"
            ],
            "columns": [
                "registration_no",
                "model",
                "kind",
                "capacity",
                "in_use"
            ],
            "fields": [
                {
                    "name": "registration_no",
                    "column": "registration_no",
                    "type": "text",
                    "required": true,
                    "max": 30
                },
                {
                    "name": "model",
                    "column": "model",
                    "type": "text",
                    "required": false,
                    "max": 80
                },
                {
                    "name": "kind",
                    "column": "kind",
                    "type": "enum",
                    "required": true,
                    "options": [
                        "truck",
                        "van",
                        "car",
                        "motorcycle"
                    ]
                },
                {
                    "name": "capacity",
                    "column": "capacity_milli",
                    "type": "quantity",
                    "required": false
                },
                {
                    "name": "bought_on",
                    "column": "bought_on",
                    "type": "date",
                    "required": false
                },
                {
                    "name": "price",
                    "column": "price_minor",
                    "type": "money",
                    "required": false
                },
                {
                    "name": "in_use",
                    "column": "in_use",
                    "type": "boolean",
                    "required": false
                },
                {
                    "name": "notes",
                    "column": "notes",
                    "type": "long_text",
                    "required": false
                }
            ]
        },
        {
            "name": "trip",
            "path": "trips",
            "title": "purpose",
            "titleColumn": "purpose",
            "search": [
                "purpose",
                "destination"
            ],
            "filters": [
                "vehicle_id",
                "driver_id"
            ],
            "columns": [
                "purpose",
                "vehicle_id",
                "driver_id",
                "trip_on",
                "distance",
                "fuel_cost"
            ],
            "fields": [
                {
                    "name": "purpose",
                    "column": "purpose",
                    "type": "text",
                    "required": true,
                    "max": 120
                },
                {
                    "name": "vehicle_id",
                    "column": "vehicle_id",
                    "type": "belongs_to",
                    "required": true,
                    "entity": "vehicle"
                },
                {
                    "name": "driver_id",
                    "column": "driver_id",
                    "type": "employee",
                    "required": false
                },
                {
                    "name": "trip_on",
                    "column": "trip_on",
                    "type": "date",
                    "required": true
                },
                {
                    "name": "destination",
                    "column": "destination",
                    "type": "text",
                    "required": false,
                    "max": 120
                },
                {
                    "name": "distance",
                    "column": "distance_milli",
                    "type": "quantity",
                    "required": false
                },
                {
                    "name": "fuel_cost",
                    "column": "fuel_cost_minor",
                    "type": "money",
                    "required": false
                }
            ]
        }
    ]
};
