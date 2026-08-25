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

export const apiMockServer =
    setupServer(
        defaultBrowserSessionBootstrap,
    );
