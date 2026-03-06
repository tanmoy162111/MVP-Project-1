<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * All platform permissions grouped by module.
     * Format: module.action
     */
    private array $permissions = [
        // ── USER MANAGEMENT ─────────────────────────────────────────────────
        'users.view', 'users.create', 'users.edit', 'users.delete',
        'users.suspend', 'users.manage_tiers', 'users.manage_credits',

        // ── VENDOR MANAGEMENT ────────────────────────────────────────────────
        'vendors.view', 'vendors.approve', 'vendors.reject',
        'vendors.suspend', 'vendors.manage_commission', 'vendors.process_payout',

        // ── PRODUCT MANAGEMENT ───────────────────────────────────────────────
        'products.view', 'products.create', 'products.edit', 'products.delete',
        'products.approve', 'products.reject', 'products.manage_categories',
        'products.manage_brands',

        // ── ORDER MANAGEMENT ─────────────────────────────────────────────────
        'orders.view', 'orders.approve', 'orders.process',
        'orders.cancel', 'orders.manage_refunds',

        // ── PRICING ──────────────────────────────────────────────────────────
        'pricing.view', 'pricing.manage_rules', 'pricing.manage_opis',
        'pricing.manage_contracts',

        // ── INVOICE & FINANCE ────────────────────────────────────────────────
        'invoices.view', 'invoices.create', 'invoices.void',
        'payments.view', 'payments.record', 'payments.refund',
        'credit_ledger.view', 'credit_ledger.adjust',

        // ── CRM ──────────────────────────────────────────────────────────────
        'crm.view', 'crm.create_log', 'crm.manage_contracts',

        // ── REPORTING ────────────────────────────────────────────────────────
        'reports.view', 'reports.export',

        // ── SYSTEM ───────────────────────────────────────────────────────────
        'system.manage_settings', 'system.manage_roles', 'system.view_logs',
    ];

    /**
     * Role definitions — each role gets a specific subset of permissions.
     */
    private array $roles = [
        'super_admin' => '*', // all permissions

        'admin' => [
            'users.view', 'users.create', 'users.edit', 'users.suspend', 'users.manage_tiers', 'users.manage_credits',
            'vendors.view', 'vendors.approve', 'vendors.reject', 'vendors.suspend', 'vendors.manage_commission', 'vendors.process_payout',
            'products.view', 'products.approve', 'products.reject', 'products.manage_categories', 'products.manage_brands',
            'orders.view', 'orders.approve', 'orders.process', 'orders.cancel', 'orders.manage_refunds',
            'pricing.view', 'pricing.manage_rules', 'pricing.manage_opis', 'pricing.manage_contracts',
            'invoices.view', 'invoices.create', 'invoices.void',
            'payments.view', 'payments.record', 'payments.refund',
            'credit_ledger.view', 'credit_ledger.adjust',
            'crm.view', 'crm.create_log', 'crm.manage_contracts',
            'reports.view', 'reports.export',
        ],

        'pricing_manager' => [
            'pricing.view', 'pricing.manage_rules', 'pricing.manage_opis', 'pricing.manage_contracts',
            'products.view',
            'reports.view',
        ],

        'order_manager' => [
            'orders.view', 'orders.approve', 'orders.process', 'orders.cancel', 'orders.manage_refunds',
            'invoices.view',
            'products.view',
            'users.view',
            'vendors.view',
        ],

        'finance_manager' => [
            'invoices.view', 'invoices.create', 'invoices.void',
            'payments.view', 'payments.record', 'payments.refund',
            'credit_ledger.view', 'credit_ledger.adjust',
            'vendors.view', 'vendors.process_payout',
            'reports.view', 'reports.export',
        ],

        'vendor' => [
            'products.view', 'products.create', 'products.edit', 'products.delete',
            'orders.view',
            'invoices.view',
            'reports.view',
        ],

        'customer' => [
            'orders.view',
            'invoices.view',
        ],
    ];

    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        DB::transaction(function () {
            // Create all permissions
            foreach ($this->permissions as $permission) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            }

            // Create roles and assign permissions
            foreach ($this->roles as $roleName => $rolePermissions) {
                $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

                if ($rolePermissions === '*') {
                    $role->givePermissionTo(Permission::all());
                } else {
                    $role->syncPermissions($rolePermissions);
                }
            }

            // Create super admin user
            $superAdmin = User::firstOrCreate(
                ['email' => config('app.admin_email', env('ADMIN_EMAIL', 'admin@example.com'))],
                [
                    'name'     => 'Super Admin',
                    'password' => bcrypt(env('ADMIN_PASSWORD', 'changeme123')),
                    'type'     => 'admin',
                    'status'   => 'active',
                    'email_verified_at' => now(),
                ]
            );

            $superAdmin->assignRole('super_admin');

            $this->command->info('✓ Roles and permissions seeded successfully.');
            $this->command->info('✓ Super admin created: ' . $superAdmin->email);
        });
    }
}
