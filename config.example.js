/*
 * Life Hub runtime configuration.
 *
 * This repository is private, but the values below are still delivered to
 * every browser that opens the dashboard. Use a dedicated, minimally
 * privileged Home Assistant account and rotate its token if it is exposed.
 */
window.LIFE_HUB_CONFIG = Object.freeze({
  oidc: Object.freeze({
    clientId: "XXXXXXXXXXXXXXXX",
    authorize: "https://XXXX.XXXX.cn/application/o/authorize/",
    token: "https://XXXX.XXXX.cn/application/o/token/",
  }),
  homeAssistant: Object.freeze({
    url: "https://home.example.com",
    token: "your-home-assistant-long-lived-access-token",
  }),
  /*
   * 本地账号密码登录（纯前端 PBKDF2 校验，适合静态托管）。
   * 生成新的密码哈希：在任意 HTTPS 页面（或 localhost）的控制台运行——
   *   const s=crypto.getRandomValues(new Uint8Array(16));
   *   const k=await crypto.subtle.importKey('raw',new TextEncoder().encode('你的密码'),'PBKDF2',false,['deriveBits']);
   *   const b=await crypto.subtle.deriveBits({name:'PBKDF2',hash:'SHA-256',salt:s,iterations:310000},k,256);
   *   const e=a=>btoa(String.fromCharCode(...a));
   *   JSON.stringify({salt:e(s),hash:e(new Uint8Array(b)),iterations:310000});
   * 把结果填入 pbkdf2，并同步修改 username。当前示例密码为 lifehub-demo，请务必更换。
   */
  localAuth: Object.freeze({
    username: "admin",
    pbkdf2: Object.freeze({
      salt: "191hlfZRuVCSZlS75hKvsg==",
      hash: "NJ/tgrpakQPH1I8pMFU0VeDEXppeAGLaxrwjdvNpeNo=",
      iterations: 310000,
    }),
  }),
});
