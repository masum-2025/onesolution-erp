<?php

// Readable names of audit actions ("module.enabled" -> "actions.module_enabled").
// An action without a name here is shown by its key.

return [

    'actions' => [
        'support_requested' => 'Support access requested',
        'support_auto_approved' => 'Support access approved by your rule',
        'support_approved' => 'Support access approved',
        'support_rejected' => 'Support access rejected',
        'support_revoked' => 'Support access ended early',
        'support_expired' => 'Support access ended (time up)',
        'support_session_started' => 'Support staff entered',
        'support_accessed' => 'Support staff opened a page',
        'data_export_requested' => 'Data export requested',
        'data_export_downloaded' => 'Data export downloaded',
        'auth_context_entered' => 'Signed in to this organization',
        'organization_created' => 'Unit created',
        'organization_updated' => 'Unit changed',
        'organization_moved' => 'Unit moved',
        'organization_plan_changed' => 'Plan changed',
        'organization_package_applied' => 'Sector package applied',
        'membership_added' => 'Member added',
        'membership_changed' => 'Membership changed',
        'membership_roles_changed' => 'Member roles changed',
        'role_created' => 'Role created',
        'role_updated' => 'Role changed',
        'role_deleted' => 'Role deleted',
        'module_enabled' => 'Module turned on',
        'module_disabled' => 'Module turned off',
        'module_inherited' => 'Module follows the level above',
        'module_consent_granted' => 'Module consent given',
        'module_consent_revoked' => 'Module consent withdrawn',
        'module_integration_tokens_revoked' => 'Integration keys revoked',
        'module_purge_requested' => 'Module data deletion scheduled',
        'module_purge_cancelled' => 'Module data deletion cancelled',
        'module_purge_executed' => 'Module data deleted',
        'auth_login' => 'Signed in',
        'rule_reset' => 'Setting reset to the level above',
        'rule_set' => 'Setting changed',
        'rule_approved' => 'Setting change approved',
        'rule_rejected' => 'Setting change rejected',
        'partner_client_created' => 'Account created by your provider',
        'partner_client_status_changed' => 'Account status changed by your provider',
        'partner_domain_added' => 'Web address added',
        'partner_domain_verified' => 'Web address verified',
        'partner_domain_removed' => 'Web address removed',
    ],

];
