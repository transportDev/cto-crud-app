import http from "k6/http";
import { check, sleep } from "k6";

export const options = {
    vus: 20,
    duration: "30s",
    thresholds: {
        http_req_failed: ["rate<0.01"],
        http_req_duration: ["p(95)<300"],
    },
};

const BASE = __ENV.BASE_URL || "http://web";

export default function () {
    const res1 = http.get(`${BASE}/`);
    check(res1, {
        "home 200": (r) => r.status === 200,
        "home html": (r) => String(r.body || "").includes("<!DOCTYPE html>"),
    });

    const res2 = http.get(`${BASE}/admin/login`);
    check(res2, {
        "login 200": (r) => r.status === 200,
        'login has "Sign in"': (r) =>
            String(r.body || "")
                .toLowerCase()
                .includes("sign in"),
    });

    const res3 = http.get(`${BASE}/up`);
    check(res3, {
        "up 200": (r) => r.status === 200,
    });

    sleep(1);
}
