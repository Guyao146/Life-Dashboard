/*
 * Life Hub runtime configuration.
 *
 * This repository is private, but the values below are still delivered to
 * every browser that opens the dashboard. Use a dedicated, minimally
 * privileged Home Assistant account and rotate its token if it is exposed.
 */
window.LIFE_HUB_CONFIG = Object.freeze({
  oidc: Object.freeze({
    clientId: "4Xg4mjRczi40YXTuox65DlfMz2nUxHzQIVtEdNxE",
    authorize: "https://login.mcylyr.cn/application/o/authorize/",
    token: "https://login.mcylyr.cn/application/o/token/",
  }),
  homeAssistant: Object.freeze({
    url: "https://home.example.com",
    token: "your-home-assistant-long-lived-access-token",
  }),
});
