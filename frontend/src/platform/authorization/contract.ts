import type {
    ApiComponents,
} from '@/platform/api';

export type PermissionName =
    ApiComponents['schemas']['PermissionName'];

export type TenantCapabilityScope =
    ApiComponents['schemas']['TenantCapabilityScope'];

export type TenantCapabilityData =
    ApiComponents['schemas']['TenantCapabilityData'];

export type TenantCapabilitySuccess =
    ApiComponents['schemas']['TenantCapabilitySuccess'];

export type WorkspaceCapabilityScope =
    ApiComponents['schemas']['WorkspaceCapabilityScope'];

export type WorkspaceCapabilityData =
    ApiComponents['schemas']['WorkspaceCapabilityData'];

export type WorkspaceCapabilitySuccess =
    ApiComponents['schemas']['WorkspaceCapabilitySuccess'];

export type CapabilityProjectionSuccess =
    | TenantCapabilitySuccess
    | WorkspaceCapabilitySuccess;
