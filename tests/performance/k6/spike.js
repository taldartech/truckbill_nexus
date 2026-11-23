import http from 'k6/http';
import { check, sleep } from 'k6';

/** Spike — dedicated non-production only. */
export const options = {
    stages: [
        { duration: '10s', target: 5 },
        { duration: '10s', target: 80 },
        { duration: '30s', target: 80 },
        { duration: '10s', target: 5 },
        { duration: '10s', target: 0 },
    ],
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';

export default function () {
    check(http.get(`${BASE_URL}/login`), { 'login ok': (r) => r.status === 200 });
    sleep(0.2);
}
