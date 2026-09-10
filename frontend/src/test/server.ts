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

export const apiMockServer =
    setupServer(
        defaultBrowserSessionBootstrap,
        defaultEmploymentTypesList,
    );