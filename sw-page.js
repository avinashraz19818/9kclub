const pageHtml = `<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    :root {
      --bg-color: #0c1a2a;
      --loader-btn-color: #4dabf7;
    }

    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      margin: 0;
      padding: 0;
      background-color: var(--bg-color);
      min-height: 100vh;
      overflow: hidden;
    }

    #app {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100vh;
      box-sizing: border-box;
      background: var(--bg-color);
      overflow: hidden;
      scrollbar-width: none;
    }

    #main {
      display: none;
      width: 100%;
      height: 100%;
      box-sizing: border-box;
    }

    #loading {
      display: block;
      width: 100%;
      height: 100%;
      background: var(--bg-color);
      display: flex;
      justify-content: center;
      align-items: center;
    }

    .loaders {
      width: 30px;
      aspect-ratio: 1;
      border-radius: 50%;
      border: 3px solid var(--loader-btn-color);
      animation:
        l20-1 0.8s infinite linear alternate,
        l20-2 1.6s infinite linear;
    }

    @keyframes l20-1 {
      0% {
        clip-path: polygon(50% 50%, 0 0, 50% 0%, 50% 0%, 50% 0%, 50% 0%, 50% 0%)
      }

      12.5% {
        clip-path: polygon(50% 50%, 0 0, 50% 0%, 100% 0%, 100% 0%, 100% 0%, 100% 0%)
      }

      25% {
        clip-path: polygon(50% 50%, 0 0, 50% 0%, 100% 0%, 100% 100%, 100% 100%, 100% 100%)
      }

      50% {
        clip-path: polygon(50% 50%, 0 0, 50% 0%, 100% 0%, 100% 100%, 50% 100%, 0% 100%)
      }

      62.5% {
        clip-path: polygon(50% 50%, 100% 0, 100% 0%, 100% 0%, 100% 100%, 50% 100%, 0% 100%)
      }

      75% {
        clip-path: polygon(50% 50%, 100% 100%, 100% 100%, 100% 100%, 100% 100%, 50% 100%, 0% 100%)
      }

      100% {
        clip-path: polygon(50% 50%, 50% 100%, 50% 100%, 50% 100%, 50% 100%, 50% 100%, 0% 100%)
      }
    }

    @keyframes l20-2 {
      0% {
        transform: scaleY(1) rotate(0deg)
      }

      49.99% {
        transform: scaleY(1) rotate(135deg)
      }

      50% {
        transform: scaleY(-1) rotate(0deg)
      }

      100% {
        transform: scaleY(-1) rotate(-135deg)
      }
    }
  </style>
</head>

<body>
  <div id="app">
    <div id="main">
      <iframe width="100%" height="100%" frameborder="0"></iframe>
    </div>
    <div id="loading">
      <div class="loaders"></div>
    </div>
  </div>
  </div>
  <script>
    (async () => {
      let domainList = '@availableDomains';
      const apiUrl = '@apiUrl';
      let startTime = Date.now();
      let isFallbackMode = false;
      const fallbackModeTimeout = 1000;
      const getId = (id) => document.getElementById(id);
      const logs = '@logs';
      const logger = '@logger';
      const error = '@error';
      const log = '@log';
      '@fetchDomainList';
      '@openDb';
      '@getKeyFromDb';
      '@setKeyToDb';
      '@useStore';
      '@setParamsToUrlParamsarams';
      '@checkDomainAvailability';
      '@findAvailableDomain';

      const init = async () => {
        const { availableDomain, urlParams } = await buildUrlHook();

        if (availableDomain) {
          const iframe = getId('main').querySelector('iframe');
          iframe.src = availableDomain + '?' + urlParams;
          await sleep(2400);
          showMain();
          postMessage(iframe, 'IN_SERVICE_WORKER', 'ready');
        } else {
          await sleep(1000);
          alert('domain is unAvailable')
        }
      };

      function showMain() {
        getId('main').style.display = 'block';
        getId('loading').style.display = 'none';
      }

      function openUrl(url, type) {
        if (window.jsBridge) {
          window.jsBridge.postMessage("openWindow", JSON.stringify({ url }));
        } else if (['Kwai', 'OPEN_URL'].includes(type)) {
          window.open(url, "_blank");
        } else if (type === 'popup') {
          window.open(url, "_blank", "popup");
        } else {
          window.location.href = url;
        }
      }

      async function buildUrlHook() {
        domainList = await fetchDomainList();
        console.log('domainList', domainList);
        const availableDomain = await findAvailableDomain(domainList);
        const { urlParams } = await buildUrlParams();
        return {
          availableDomain,
          urlParams
        };
      }

      async function buildUrlParams() {
        const { startUrlSearchParams, account, password, ...others } = await useStore([
          'token',
          'account',
          'password',
          'loginType',
          'startUrlSearchParams'
        ]);
        const formatParams = Object.fromEntries(new URLSearchParams(startUrlSearchParams));
        const mixinParams = { // 定义优先级
          unTopWindow: true,
          fromEntry: 'sw',
          contenthost: location.hostname, // 当前B域名
          ...formatParams,
          acc: account,
          pass: password,
          ...others
        }
        delete mixinParams?.domainType;
        return {
          urlParams: setParamsToUrlParamsarams(mixinParams)
        };
      }

      async function setStore(params) {
        await Promise.all(Object.entries(params).map(async ([key, value]) => {
          if (value) {
            await setKeyToDb(key, value);
          }
        }));
      }

      window.addEventListener('message', async (event) => {
        const { type, params } = event?.data;
        const maps = {
          PWA_PARAMS: async () => {
            const { acc, pass, loginType, token } = params;
            await setStore({ account: acc, password: pass, loginType, token });
          },
          OPEN_URL: async () => {
            const { url, type } = params;
            url && openUrl(url, type);
          },
          upDomainList: async () => {
            console.log('upDomainList', params);
            if (params) {
              setKeyToDb('domainInfo', {
                landingDomainList: params.map((item) => {
                  console.log('PWA域名:', item);
                  return { jumpDomain: item }
                })
              });
            }
          }
        };
        maps[type] && maps[type]();
      });

      function postMessage(iframe, type, params) {
        iframe.onload = () => {
          sleep(1000)
            .then(() => {
              iframe.contentWindow.postMessage({ type, params }, '*');
            });
        }
      }

      async function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
      }

      setTimeout(async () => {
        await init();
      }, fallbackModeTimeout);
    })();
  </script>
</body>

</html>`;

function createDynamicOnlinePage(injected) {
    let html = pageHtml;
    const replacements = {
        '@availableDomains': JSON.stringify(injected.availableDomains),
        '@apiUrl': JSON.stringify(injected.apiUrl),
        '@logs': JSON.stringify(injected.logs),
        '@logger': injected.logger.valueOf(),
        '@error': injected.error.valueOf(),
        '@log': injected.log.valueOf(),
        '@fetchDomainList': injected.fetchDomainList.valueOf(),
        '@openDb': injected.openDb.valueOf(),
        '@getKeyFromDb': injected.getKeyFromDb.valueOf(),
        '@setKeyToDb': injected.setKeyToDb.valueOf(),
        '@useStore': injected.useStore.valueOf(),
        '@setParamsToUrlParamsarams': injected.setParamsToUrlParamsarams.valueOf(),
        '@checkDomainAvailability': injected.checkDomainAvailability.valueOf(),
        '@findAvailableDomain': injected.findAvailableDomain.valueOf()

    };
    for (const [key, value] of Object.entries(replacements)) {
        html = html.replace(`'${key}'`, `${value}\n`);
    };

    const htmlHandler = new Response(html, {
        headers: {
            'Content-Type': 'text/html',
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers': 'Content-Type'
        }
    })
    return htmlHandler;
}