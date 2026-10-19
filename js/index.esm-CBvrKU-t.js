import{l as N,q as y,e as L,y as W,E as Y,z as O,u as x,t as k,v as z,L as H,h as E,C,r as D,A as P,F as V}from"./index.esm2017-DjCRxkGx.js";import"./index.esm2017-DB_Ul6L_.js";/**
 * @license
 * Copyright 2019 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */const v="analytics",J="firebase_id",Q="origin",X=60*1e3,Z="https://firebase.googleapis.com/v1alpha/projects/-/apps/{app-id}/webConfig",_="https://www.googletagmanager.com/gtag/js";/**
 * @license
 * Copyright 2019 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */const l=new H("@firebase/analytics");/**
 * @license
 * Copyright 2019 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */const ee={"already-exists":"A Firebase Analytics instance with the appId {$id}  already exists. Only one Firebase Analytics instance can be created for each appId.","already-initialized":"initializeAnalytics() cannot be called again with different options than those it was initially called with. It can be called again with the same options to return the existing instance, or getAnalytics() can be used to get a reference to the already-initialized instance.","already-initialized-settings":"Firebase Analytics has already been initialized.settings() must be called before initializing any Analytics instanceor it will have no effect.","interop-component-reg-failed":"Firebase Analytics Interop Component failed to instantiate: {$reason}","invalid-analytics-context":"Firebase Analytics is not supported in this environment. Wrap initialization of analytics in analytics.isSupported() to prevent initialization in unsupported environments. Details: {$errorInfo}","indexeddb-unavailable":"IndexedDB unavailable or restricted in this environment. Wrap initialization of analytics in analytics.isSupported() to prevent initialization in unsupported environments. Details: {$errorInfo}","fetch-throttle":"The config fetch request timed out while in an exponential backoff state. Unix timestamp in milliseconds when fetch request throttling ends: {$throttleEndTimeMillis}.","config-fetch-failed":"Dynamic config fetch failed: [{$httpStatus}] {$responseMessage}","no-api-key":'The "apiKey" field is empty in the local Firebase config. Firebase Analytics requires this field tocontain a valid API key.',"no-app-id":'The "appId" field is empty in the local Firebase config. Firebase Analytics requires this field tocontain a valid app ID.',"no-client-id":'The "client_id" field is empty.',"invalid-gtag-resource":"Trusted Types detected an invalid gtag resource: {$gtagURL}."},u=new Y("analytics","Analytics",ee);/**
 * @license
 * Copyright 2019 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */function te(e){if(!e.startsWith(_)){const t=u.create("invalid-gtag-resource",{gtagURL:e});return l.warn(t.message),""}return e}function G(e){return Promise.all(e.map(t=>t.catch(n=>n)))}function ne(e,t){let n;return window.trustedTypes&&(n=window.trustedTypes.createPolicy(e,t)),n}function ie(e,t){const n=ne("firebase-js-sdk-policy",{createScriptURL:te}),a=document.createElement("script"),i=`${_}?l=${e}&id=${t}`;a.src=n?n==null?void 0:n.createScriptURL(i):i,a.async=!0,document.head.appendChild(a)}function ae(e){let t=[];return Array.isArray(window[e])?t=window[e]:window[e]=t,t}async function se(e,t,n,a,i,s){const r=a[i];try{if(r)await t[r];else{const c=(await G(n)).find(d=>d.measurementId===i);c&&await t[c.appId]}}catch(o){l.error(o)}e("config",i,s)}async function re(e,t,n,a,i){try{let s=[];if(i&&i.send_to){let r=i.send_to;Array.isArray(r)||(r=[r]);const o=await G(n);for(const c of r){const d=o.find(g=>g.measurementId===c),m=d&&t[d.appId];if(m)s.push(m);else{s=[];break}}}s.length===0&&(s=Object.values(t)),await Promise.all(s),e("event",a,i||{})}catch(s){l.error(s)}}function oe(e,t,n,a){async function i(s,...r){try{if(s==="event"){const[o,c]=r;await re(e,t,n,o,c)}else if(s==="config"){const[o,c]=r;await se(e,t,n,a,o,c)}else if(s==="consent"){const[o,c]=r;e("consent",o,c)}else if(s==="get"){const[o,c,d]=r;e("get",o,c,d)}else if(s==="set"){const[o]=r;e("set",o)}else e(s,...r)}catch(o){l.error(o)}}return i}function ce(e,t,n,a,i){let s=function(...r){window[a].push(arguments)};return window[i]&&typeof window[i]=="function"&&(s=window[i]),window[i]=oe(s,e,t,n),{gtagCore:s,wrappedGtag:window[i]}}function le(e){const t=window.document.getElementsByTagName("script");for(const n of Object.values(t))if(n.src&&n.src.includes(_)&&n.src.includes(e))return n;return null}/**
 * @license
 * Copyright 2020 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */const de=30,ue=1e3;class fe{constructor(t={},n=ue){this.throttleMetadata=t,this.intervalMillis=n}getThrottleMetadata(t){return this.throttleMetadata[t]}setThrottleMetadata(t,n){this.throttleMetadata[t]=n}deleteThrottleMetadata(t){delete this.throttleMetadata[t]}}const U=new fe;function pe(e){return new Headers({Accept:"application/json","x-goog-api-key":e})}async function me(e){var t;const{appId:n,apiKey:a}=e,i={method:"GET",headers:pe(a)},s=Z.replace("{app-id}",n),r=await fetch(s,i);if(r.status!==200&&r.status!==304){let o="";try{const c=await r.json();!((t=c.error)===null||t===void 0)&&t.message&&(o=c.error.message)}catch{}throw u.create("config-fetch-failed",{httpStatus:r.status,responseMessage:o})}return r.json()}async function he(e,t=U,n){const{appId:a,apiKey:i,measurementId:s}=e.options;if(!a)throw u.create("no-app-id");if(!i){if(s)return{measurementId:s,appId:a};throw u.create("no-api-key")}const r=t.getThrottleMetadata(a)||{backoffCount:0,throttleEndTimeMillis:Date.now()},o=new we;return setTimeout(async()=>{o.abort()},X),j({appId:a,apiKey:i,measurementId:s},r,o,t)}async function j(e,{throttleEndTimeMillis:t,backoffCount:n},a,i=U){var s;const{appId:r,measurementId:o}=e;try{await ge(a,t)}catch(c){if(o)return l.warn(`Timed out fetching this Firebase app's measurement ID from the server. Falling back to the measurement ID ${o} provided in the "measurementId" field in the local Firebase config. [${c==null?void 0:c.message}]`),{appId:r,measurementId:o};throw c}try{const c=await me(e);return i.deleteThrottleMetadata(r),c}catch(c){const d=c;if(!ye(d)){if(i.deleteThrottleMetadata(r),o)return l.warn(`Failed to fetch this Firebase app's measurement ID from the server. Falling back to the measurement ID ${o} provided in the "measurementId" field in the local Firebase config. [${d==null?void 0:d.message}]`),{appId:r,measurementId:o};throw c}const m=Number((s=d==null?void 0:d.customData)===null||s===void 0?void 0:s.httpStatus)===503?P(n,i.intervalMillis,de):P(n,i.intervalMillis),g={throttleEndTimeMillis:Date.now()+m,backoffCount:n+1};return i.setThrottleMetadata(r,g),l.debug(`Calling attemptFetch again in ${m} millis`),j(e,g,a,i)}}function ge(e,t){return new Promise((n,a)=>{const i=Math.max(t-Date.now(),0),s=setTimeout(n,i);e.addEventListener(()=>{clearTimeout(s),a(u.create("fetch-throttle",{throttleEndTimeMillis:t}))})})}function ye(e){if(!(e instanceof V)||!e.customData)return!1;const t=Number(e.customData.httpStatus);return t===429||t===500||t===503||t===504}class we{constructor(){this.listeners=[]}addEventListener(t){this.listeners.push(t)}abort(){this.listeners.forEach(t=>t())}}/**
 * @license
 * Copyright 2019 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */let b;async function Ie(e,t,n,a,i){if(i&&i.global){e("event",n,a);return}else{const s=await t,r=Object.assign(Object.assign({},a),{send_to:s});e("event",n,r)}}async function ve(e,t,n,a){if(a&&a.global)return e("set",{screen_name:n}),Promise.resolve();{const i=await t;e("config",i,{update:!0,screen_name:n})}}async function be(e,t,n,a){if(a&&a.global)return e("set",{user_id:n}),Promise.resolve();{const i=await t;e("config",i,{update:!0,user_id:n})}}async function Te(e,t,n,a){if(a&&a.global){const i={};for(const s of Object.keys(n))i[`user_properties.${s}`]=n[s];return e("set",i),Promise.resolve()}else{const i=await t;e("config",i,{update:!0,user_properties:n})}}async function Ae(e,t){const n=await t;return new Promise((a,i)=>{e("get",n,"client_id",s=>{s||i(u.create("no-client-id")),a(s)})})}async function _e(e,t){const n=await e;window[`ga-disable-${n}`]=!t}let T;function B(e){T=e}function q(e){b=e}/**
 * @license
 * Copyright 2020 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */async function Ee(){if(k())try{await z()}catch(e){return l.warn(u.create("indexeddb-unavailable",{errorInfo:e==null?void 0:e.toString()}).message),!1}else return l.warn(u.create("indexeddb-unavailable",{errorInfo:"IndexedDB is not available in this environment."}).message),!1;return!0}async function Ce(e,t,n,a,i,s,r){var o;const c=he(e);c.then(h=>{n[h.measurementId]=h.appId,e.options.measurementId&&h.measurementId!==e.options.measurementId&&l.warn(`The measurement ID in the local Firebase config (${e.options.measurementId}) does not match the measurement ID fetched from the server (${h.measurementId}). To ensure analytics events are always sent to the correct Analytics property, update the measurement ID field in the local config or remove it from the local config.`)}).catch(h=>l.error(h)),t.push(c);const d=Ee().then(h=>{if(h)return a.getId()}),[m,g]=await Promise.all([c,d]);le(s)||ie(s,m.measurementId),T&&(i("consent","default",T),B(void 0)),i("js",new Date);const w=(o=r==null?void 0:r.config)!==null&&o!==void 0?o:{};return w[Q]="firebase",w.update=!0,g!=null&&(w[J]=g),i("config",m.measurementId,w),b&&(i("set",b),q(void 0)),m.measurementId}/**
 * @license
 * Copyright 2019 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */class De{constructor(t){this.app=t}_delete(){return delete f[this.app.options.appId],Promise.resolve()}}let f={},M=[];const R={};let I="dataLayer",K="gtag",F,p,A=!1;function Oe(e){if(A)throw u.create("already-initialized");e.dataLayerName&&(I=e.dataLayerName),e.gtagName&&(K=e.gtagName)}function Pe(){const e=[];if(O()&&e.push("This is a browser extension environment."),x()||e.push("Cookies are not available."),e.length>0){const t=e.map((a,i)=>`(${i+1}) ${a}`).join(" "),n=u.create("invalid-analytics-context",{errorInfo:t});l.warn(n.message)}}function Me(e,t,n){Pe();const a=e.options.appId;if(!a)throw u.create("no-app-id");if(!e.options.apiKey)if(e.options.measurementId)l.warn(`The "apiKey" field is empty in the local Firebase config. This is needed to fetch the latest measurement ID for this Firebase app. Falling back to the measurement ID ${e.options.measurementId} provided in the "measurementId" field in the local Firebase config.`);else throw u.create("no-api-key");if(f[a]!=null)throw u.create("already-exists",{id:a});if(!A){ae(I);const{wrappedGtag:s,gtagCore:r}=ce(f,M,R,I,K);p=s,F=r,A=!0}return f[a]=Ce(e,M,R,t,F,I,n),new De(e)}function xe(e=N()){e=y(e);const t=L(e,v);return t.isInitialized()?t.getImmediate():Re(e)}function Re(e,t={}){const n=L(e,v);if(n.isInitialized()){const i=n.getImmediate();if(W(t,n.getOptions()))return i;throw u.create("already-initialized")}return n.initialize({options:t})}async function ke(){if(O()||!x()||!k())return!1;try{return await z()}catch{return!1}}function ze(e,t,n){e=y(e),ve(p,f[e.app.options.appId],t,n).catch(a=>l.error(a))}async function Ge(e){return e=y(e),Ae(p,f[e.app.options.appId])}function Ue(e,t,n){e=y(e),be(p,f[e.app.options.appId],t,n).catch(a=>l.error(a))}function je(e,t,n){e=y(e),Te(p,f[e.app.options.appId],t,n).catch(a=>l.error(a))}function Be(e,t){e=y(e),_e(f[e.app.options.appId],t).catch(n=>l.error(n))}function qe(e){p?p("set",e):q(e)}function Fe(e,t,n,a){e=y(e),Ie(p,f[e.app.options.appId],t,n,a).catch(i=>l.error(i))}function Ke(e){p?p("consent","update",e):B(e)}const $="@firebase/analytics",S="0.10.17";function $e(){E(new C(v,(t,{options:n})=>{const a=t.getProvider("app").getImmediate(),i=t.getProvider("installations-internal").getImmediate();return Me(a,i,n)},"PUBLIC")),E(new C("analytics-internal",e,"PRIVATE")),D($,S),D($,S,"esm2017");function e(t){try{const n=t.getProvider(v).getImmediate();return{logEvent:(a,i,s)=>Fe(n,a,i,s)}}catch(n){throw u.create("interop-component-reg-failed",{reason:n})}}}$e();export{xe as getAnalytics,Ge as getGoogleAnalyticsClientId,Re as initializeAnalytics,ke as isSupported,Fe as logEvent,Be as setAnalyticsCollectionEnabled,Ke as setConsent,ze as setCurrentScreen,qe as setDefaultEventParameters,Ue as setUserId,je as setUserProperties,Oe as settings};
