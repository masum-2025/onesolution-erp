<?php

return [

    'errors' => [
        'role_not_found' => 'Role not found. It may have been deleted, or it belongs to another unit.',
        'template_not_found' => 'This role template is not available for your sector. Choose another template.',
        'cannot_grant' => 'You cannot give permissions you do not hold yourself: :permissions. Ask someone who holds them.',
        'module_disabled' => 'These permissions belong to a module that is turned off here: :permissions. Turn the module on first.',
        'separation_of_duties' => 'One person cannot hold both ":first" and ":second". Give them to different people.',
        'separation_of_duties_members' => 'This change would give :count member(s) both ":first" and ":second". Change their roles first.',
        'role_in_use' => 'This role is still given to :count member(s). Remove it from them first, then delete it.',
        'version_conflict' => 'Someone else changed this role while you were editing. Reload it to see their changes, then apply yours again.',
        'role_not_usable' => 'One of the roles cannot be used here. Only roles of this unit or the units above it can be given.',
        'portal_membership' => 'Portal members (parents, customers, patients) cannot hold staff roles.',
        'own_roles' => 'You cannot change your own roles. Ask another administrator to do it.',
        'owner_only' => 'Only an owner can make someone an owner or change an owner.',
    ],

    'validation' => [
        'name_required' => 'Enter a name for the role in at least one language.',
        'unknown_permission' => 'This permission does not exist. Reload the page and choose again.',
        'permissions_or_template' => 'Choose the permissions for the role, or start from a template.',
    ],

    'messages' => [
        'role_created' => 'Role created.',
        'role_saved' => 'Role saved.',
        'role_deleted' => 'Role deleted.',
    ],

    // Permission groups of the platform itself (modules use their own names).
    'groups' => [
        'organization' => 'Organization',
        'people' => 'People and roles',
        'setup' => 'Setup',
        'security' => 'Security and data',
        'billing' => 'Billing',
    ],

    'permissions' => [
        'organizations_manage' => 'Add and edit units',
        'organizations_move' => 'Move units in the structure',
        'members_manage' => 'Add members and give roles',
        'roles_manage' => 'Create and edit roles',
        'modules_manage' => 'Turn modules on and off',
        'rules_approve' => 'Approve setting changes',
        'rules_edit' => 'Edit :module settings',
        'support_approve' => 'Approve support access',
        'audit_view' => 'View the audit log',
        'data_export' => 'Export all data',
        'billing_view' => 'See plan and invoices',
        'branding_manage' => 'Change the organization\'s brand',
    ],

    'templates' => [
        'administrator' => [
            'name' => 'Administrator',
            'description' => 'Runs the unit: structure, people, roles, modules and settings.',
        ],
        'manager' => [
            'name' => 'Manager',
            'description' => 'Works with every module, without changing people, roles or modules.',
        ],
        'staff' => [
            'name' => 'Staff',
            'description' => 'Views information and uses everyday tools.',
        ],
        'accountant' => [
            'name' => 'Accountant',
            'description' => 'Posts journals and runs payroll. Someone else approves them.',
        ],
        'finance_approver' => [
            'name' => 'Finance approver',
            'description' => 'Approves journals, payroll and setting changes prepared by others.',
        ],
        'hr_officer' => [
            'name' => 'HR officer',
            'description' => 'Manages employees and attendance, and adds members.',
        ],
        'principal' => [
            'name' => 'Principal',
            'description' => 'Oversees the school and approves payroll, accounts and settings.',
        ],
        'teacher' => [
            'name' => 'Teacher',
            'description' => 'Takes attendance and views staff information.',
        ],
        'office_staff' => [
            'name' => 'Office staff',
            'description' => 'Handles admission contacts, stock and day-to-day account entries.',
        ],
    ],

];
