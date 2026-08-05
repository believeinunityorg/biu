/**
 * BIU ramp (soak) load test — still read-only; default STAGING.
 *
 * Usage:
 *   k6 run loadtests/ramp.js
 *   k6 run -e BASE_URL=https://501c3ers.com -e MAX_VUS=50 loadtests/ramp.js
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || 'https://501c3ers.com').replace(/\/$/, '');
const MAX_VUS = Number(__ENV.MAX_VUS || 40);

const errorRate = new Rate('biu_errors');

export const options = {
  stages: [
    { duration: '30s', target: Math.min(10, MAX_VUS) },
    { duration: '1m', target: Math.min(25, MAX_VUS) },
    { duration: '2m', target: MAX_VUS },
    { duration: '1m', target: MAX_VUS },
    { duration: '30s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.08'],
    biu_errors: ['rate<0.08'],
    http_req_duration: ['p(95)<5000'],
  },
};

export default function () {
  // Health-only by default — Cloudflare often challenges browser HTML on /
  const paths = ['/up', '/api/health'];
  for (const path of paths) {
    const res = http.get(`${BASE_URL}${path}`, {
      tags: { name: path },
      timeout: '30s',
      headers: {
        'User-Agent': 'BIU-k6-loadtest/1.0 (+ops; read-only health)',
        Accept: 'application/json,text/plain,*/*',
      },
    });
    const ok = check(res, {
      '2xx': (r) => r.status >= 200 && r.status < 300,
    });
    errorRate.add(!ok);
    sleep(0.3);
  }
  sleep(0.5);
}
