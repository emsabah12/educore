import type { ApiComponents } from '@/platform/api';

export type BrowserLoginRequest =
    ApiComponents['schemas']['LoginTokenRequest'];

export type BrowserLoginData =
    ApiComponents['schemas']['BrowserLoginData'];

export type BrowserLoginSuccess =
    ApiComponents['schemas']['BrowserLoginSuccess'];

export type BrowserLogoutSuccess =
    ApiComponents['schemas']['BrowserLogoutSuccess'];

export type AuthenticatedBootstrapData =
    ApiComponents['schemas']['AuthenticatedBootstrapData'];

export type AuthenticatedBootstrapSuccess =
    ApiComponents['schemas']['AuthenticatedBootstrapSuccess'];
