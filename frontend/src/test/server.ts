import {
    http,
    HttpResponse,
} from 'msw';
import {
    setupServer,
} from 'msw/node';

const defaultBrowserSessionBootstrap =
    http.get(
        /\/api\/v1\/browser\/session\/csrf$/,
        () =>
            new HttpResponse(
                null,
                {
                    status:
                        204,
                },
            ),
    );

/*
 * CreateEmploymentForm (HrEmployeeDetailPage) always calls
 * useEmploymentTypesQuery on mount regardless of whether the
 * form is open, so every test touching that page needs this
 * endpoint mocked — registered globally here (like the CSRF
 * bootstrap above) rather than repeated per test file.
 */
const defaultEmploymentTypesList =
    http.get(
        /\/api\/v1\/hr\/employment-types$/,
        () =>
            HttpResponse.json(
                {
                    status: 'success',
                    data: [],
                },
            ),
    );

/*
 * useApplicationNavigationProjection calls
 * useTenantEffectiveFeaturesQuery on every render of the
 * authenticated shell (via ApplicationNavigation), so any
 * full-integration test that reaches that shell needs this
 * endpoint mocked. Defaults to no features — a test that needs
 * a feature-gated nav entry visible overrides this with
 * apiMockServer.use(...).
 */
const defaultTenantEffectiveFeatures =
    http.get(
        /\/api\/v1\/core\/tenant-subscription\/effective-features$/,
        () =>
            HttpResponse.json(
                {
                    status: 'success',
                    data: {
                        feature_codes: [],
                    },
                },
            ),
    );

export const apiMockServer =
    setupServer(
        defaultBrowserSessionBootstrap,
        defaultEmploymentTypesList,
        defaultTenantEffectiveFeatures,
    );
