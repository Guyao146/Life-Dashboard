/*
 * Copy to config.js and fill in your own values. config.js is git-ignored.
 * Static hosting means HA_TOKEN is sent by the browser to Home Assistant.
 * Use a dedicated, minimally privileged HA account/token and keep the site private.
 */
window.LIFE_HUB_CONFIG = {
  oidc: {
    clientId: 'your-authentik-public-client-id',
    authorize: 'https://login.example.com/application/o/authorize/',
    token: 'https://login.example.com/application/o/token/'
  },
  homeAssistant: {
    url: 'https://home.example.com',
    token: 'your-home-assistant-long-lived-access-token'
  }
};