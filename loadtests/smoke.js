/**
 * BIU smoke load test — Production Roadmap (k6).
 *
 * SAFE defaults:
 * - Targets read-only health endpoints (/up, /api/health)
 * - Defaults to STAGING (501c3ers.com)
 * - Does NOT create accounts, post forms, or hit payment/auth writes
 * - Home (/) optional via INCLUDE_HOME=1 (Cloudflare Bot Fight often blocks it)
 *
 * Note: Behind Cloudflare Bot Fight, concurrent scripted GETs often get non-2xx.
 * Thresholds therefore emphasize latency of *successful* responses + a soft error budget.
 *
 * Usage:
 *   k6 run loadtests/smoke.js
 *   k6 run -e BASE_URL=https://501c3ers.com -e VUS=20 -e DURATION=2m loadtests/smoke.js
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || 'https://501c3ers.com').replace(/\/$/, '');
const VUS = Number(__ENV.VUS || 10);
const DURATION = __ENV.DURATION || '1m';
const INCLUDE_HOME = (__ENV.INCLUDE_HOME || '0') === '1';

const errorRate = new Rate('biu_errors');
const okCount = new Counter('biu_ok');
const failCount = new Counter('biu_fail');
const healthMs = new Trend('biu_health_duration', true);
const upMs = new Trend('biu_up_duration', true);
const homeMs = new Trend('biu_home_duration', true);
const okDuration = new Trend('biu_ok_duration', true);

export const options = {
  scenarios: {
    smoke: {
      executor: 'constant-vus',
      vus: VUS,
      duration: DURATION,
      gracefulStop: '15s',
    },
  },
  thresholds: {
    // Latency of successful responses (CF challenges inflate http_req_failed)
    biu_ok_duration: ['p(95)<2000', 'p(99)<4000'],
    biu_health_duration: ['p(95)<2000'],
    biu_up_duration: ['p(95)<2000'],
    // Soft error budget — raise awareness if everything is blocked
    biu_errors: ['rate<0.85'],
    checks: ['rate>0.15'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

function hit(path, trend) {
  const res = http.get(`${BASE_URL}${path}`, {
    tags: { name: path },
    timeout: '30s',
    redirects: 5,
    headers: {
      'User-Agent': 'BIU-k6-loadtest/1.0 (+ops; read-only health)',
      Accept: 'application/json,text/plain,*/*',
    },
  });
  trend.add(res.timings.duration);
  const ok = res.status >= 200 && res.status < 300;
  check(res, {
    [`${path} status is 2xx`]: () => ok,
  });
  errorRate.add(!ok);
  if (ok) {
    okCount.add(1);
    okDuration.add(res.timings.duration);
  } else {
    failCount.add(1);
  }
  return res;
}

export default function () {
  hit('/up', upMs);
  sleep(0.2);
  hit('/api/health', healthMs);
  if (INCLUDE_HOME) {
    sleep(0.3);
    hit('/', homeMs);
  }
  sleep(0.5);
}

export function handleSummary(data) {
  const lines = [
    `BIU k6 smoke — ${BASE_URL}`,
    `VUs=${VUS} duration=${DURATION} include_home=${INCLUDE_HOME}`,
    `ok=${data.metrics.biu_ok?.values?.count ?? 0} fail=${data.metrics.biu_fail?.values?.count ?? 0}`,
    `error_rate=${(data.metrics.biu_errors?.values?.rate ?? 0).toFixed(4)}`,
    `ok_p95=${(data.metrics.biu_ok_duration?.values['p(95)'] ?? 0).toFixed(1)}ms`,
    `health_p95=${(data.metrics.biu_health_duration?.values['p(95)'] ?? 0).toFixed(1)}ms`,
    `up_p95=${(data.metrics.biu_up_duration?.values['p(95)'] ?? 0).toFixed(1)}ms`,
  ];
  if (INCLUDE_HOME) {
    lines.push(`home_p95=${(data.metrics.biu_home_duration?.values['p(95)'] ?? 0).toFixed(1)}ms`);
  }
  return {
    stdout: `\n${lines.join('\n')}\n`,
    'loadtests/results/last-smoke-summary.txt': `${lines.join('\n')}\n`,
  };
}
