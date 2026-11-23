import http from 'k6/http';
import { check, sleep } from 'k6';

/** Load profile — scheduled dedicated environment only. Never production. */
export const options = {
    stages: [
        { duration: '2m', target: 20 },
        { duration: '5m', target: 20 },
        { duration: '2m', target: 0 },
    ],
    thresholds: {
        http_req_failed: ['rate<0.05'],
        http_req_duration: ['p(95)<2500'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';

export default function () {
    const res = http.get(`${BASE_URL}/login`);
    check(res, { 'login 200': (r) => r.status === 200 });
    sleep(0.5);
}
