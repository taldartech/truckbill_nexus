import http from 'k6/http';
import { check, sleep } from 'k6';

/**
 * k6 baseline — dedicated non-production environment only.
 */
export const options = {
    stages: [
        { duration: '1m', target: 5 },
        { duration: '3m', target: 5 },
        { duration: '1m', target: 0 },
    ],
    thresholds: {
        http_req_failed: ['rate<0.02'],
        http_req_duration: ['p(95)<1500', 'p(99)<3000'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';

export default function () {
    const login = http.get(`${BASE_URL}/login`);
    check(login, { 'login 200': (r) => r.status === 200 });

    const home = http.get(`${BASE_URL}/`);
    check(home, { 'home responds': (r) => r.status === 200 || r.status === 302 });

    sleep(1);
}
