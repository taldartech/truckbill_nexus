import http from 'k6/http';
import { check, sleep } from 'k6';

/**
 * k6 smoke — non-production only (PO-P1-DEPS-K6).
 * Usage: k6 run -e BASE_URL=http://127.0.0.1:8000 tests/performance/k6/smoke.js
 */
export const options = {
    vus: 1,
    duration: '30s',
    thresholds: {
        http_req_failed: ['rate<0.05'],
        http_req_duration: ['p(95)<2000'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';

export default function () {
    const res = http.get(`${BASE_URL}/login`);
    check(res, {
        'login status is 200': (r) => r.status === 200,
    });
    sleep(1);
}
