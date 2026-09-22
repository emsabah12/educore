import type { ApiComponents } from '@/platform/api';

export type BrowserLoginRequest =
    ApiComponents['schemas']['LoginTokenRequest'];

export type BrowserLoginData =
    ApiComponents['schemas']['BrowserLoginData'];

export type BrowserLoginSuccess =
    ApiComponents['schemas']['BrowserLoginSuccess'];

export type BrowserLogoutSuccess =
    ApiComponents['schemas']['BrowserLogoutSuccess'];

/**
 * §Pendaftaran tenant mandiri (self-service) — TenantRegistrationData
 * SECARA STRUKTUR adalah superset dari BrowserLoginData (field
 * context_type/user/platform SAMA PERSIS, ditambah field `tenant`
 * yang HANYA untuk keperluan tampilan/UX di halaman pendaftaran itu
 * sendiri). State auth TIDAK PERNAH mempercayai field `tenant` ini
 * sebagai Tenant/Membership context resmi — itu tetap harus
 * di-discover ulang lewat MembershipRuntime canonical, sama seperti
 * setelah login biasa (lihat catatan di BrowserAuthRuntime.register()).
 */
export type TenantRegistrationRequest =
    ApiComponents['schemas']['RegisterTenantRequestBody'];

export type TenantRegistrationData =
    ApiComponents['schemas']['TenantRegistrationData'];

export type TenantRegistrationSuccess =
    ApiComponents['schemas']['TenantRegistrationSuccess'];

export type AuthenticatedBootstrapData =
    ApiComponents['schemas']['AuthenticatedBootstrapData'];

export type AuthenticatedBootstrapSuccess =
    ApiComponents['schemas']['AuthenticatedBootstrapSuccess'];
