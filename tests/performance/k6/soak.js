import http from 'k6/http';
import { check, sleep } from 'k6';

/** Soak — long-running dedicated non-production only. */
export const options = {
    stages: [
        { duration: '5m', target: 10 },
        { duration: '30m', target: 10 },
        { duration: '5m', target: 0 },
    ],
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';

export default function () {
    check(http.get(`${BASE_URL}/login`), { 'login ok': (r) => r.status === 200 });
    sleep(1);
}
