import"./index.esm2017-DB_Ul6L_.js";import{l as $,q as g,E as V,e as U,v as q,t as G,u as Y,w as R,x as b,h as E,C as I,r as A}from"./index.esm2017-DjCRxkGx.js";/**
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
 */const J="/firebase-messaging-sw.js",z="/firebase-cloud-messaging-push-scope",j="BDOU99-h67HcA6JeFXHbSNMu7e2yNNu3RzoMj8TM4W88jITfq7ZmPvIM1Iv-4_l2LxQcYwhqby2xGpWwzjfAnG4",Q="https://fcmregistrations.googleapis.com/v1",F="google.c.a.c_id",X="google.c.a.c_l",Z="google.c.a.ts",ee="google.c.a.e",D=1e4;var O;(function(e){e[e.DATA_MESSAGE=1]="DATA_MESSAGE",e[e.DISPLAY_NOTIFICATION=3]="DISPLAY_NOTIFICATION"})(O||(O={}));/**
 * @license
 * Copyright 2018 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except
 * in compliance with the License. You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software distributed under the License
 * is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express
 * or implied. See the License for the specific language governing permissions and limitations under
 * the License.
 */var l;(function(e){e.PUSH_RECEIVED="push-received",e.NOTIFICATION_CLICKED="notification-clicked"})(l||(l={}));/**
 * @license
 * Copyright 2017 Google LLC
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
 */function d(e){const t=new Uint8Array(e);return btoa(String.fromCharCode(...t)).replace(/=/g,"").replace(/\+/g,"-").replace(/\//g,"_")}function te(e){const t="=".repeat((4-e.length%4)%4),n=(e+t).replace(/\-/g,"+").replace(/_/g,"/"),o=atob(n),i=new Uint8Array(o.length);for(let r=0;r<o.length;++r)i[r]=o.charCodeAt(r);return i}/**
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
 */const h="fcm_token_details_db",ne=5,M="fcm_token_object_Store";async function oe(e){if("databases"in indexedDB&&!(await indexedDB.databases()).map(r=>r.name).includes(h))return null;let t=null;return(await R(h,ne,{upgrade:async(o,i,r,s)=>{var p;if(i<2||!o.objectStoreNames.contains(M))return;const f=s.objectStore(M),w=await f.index("fcmSenderId").get(e);if(await f.clear(),!!w){if(i===2){const a=w;if(!a.auth||!a.p256dh||!a.endpoint)return;t={token:a.fcmToken,createTime:(p=a.createTime)!==null&&p!==void 0?p:Date.now(),subscriptionOptions:{auth:a.auth,p256dh:a.p256dh,endpoint:a.endpoint,swScope:a.swScope,vapidKey:typeof a.vapidKey=="string"?a.vapidKey:d(a.vapidKey)}}}else if(i===3){const a=w;t={token:a.fcmToken,createTime:a.createTime,subscriptionOptions:{auth:d(a.auth),p256dh:d(a.p256dh),endpoint:a.endpoint,swScope:a.swScope,vapidKey:d(a.vapidKey)}}}else if(i===4){const a=w;t={token:a.fcmToken,createTime:a.createTime,subscriptionOptions:{auth:d(a.auth),p256dh:d(a.p256dh),endpoint:a.endpoint,swScope:a.swScope,vapidKey:d(a.vapidKey)}}}}}})).close(),await b(h),await b("fcm_vapid_details_db"),await b("undefined"),ie(t)?t:null}function ie(e){if(!e||!e.subscriptionOptions)return!1;const{subscriptionOptions:t}=e;return typeof e.createTime=="number"&&e.createTime>0&&typeof e.token=="string"&&e.token.length>0&&typeof t.auth=="string"&&t.auth.length>0&&typeof t.p256dh=="string"&&t.p256dh.length>0&&typeof t.endpoint=="string"&&t.endpoint.length>0&&typeof t.swScope=="string"&&t.swScope.length>0&&typeof t.vapidKey=="string"&&t.vapidKey.length>0}/**
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
 */const re="firebase-messaging-database",ae=1,u="firebase-messaging-store";let y=null;function k(){return y||(y=R(re,ae,{upgrade:(e,t)=>{switch(t){case 0:e.createObjectStore(u)}}})),y}async function L(e){const t=T(e),o=await(await k()).transaction(u).objectStore(u).get(t);if(o)return o;{const i=await oe(e.appConfig.senderId);if(i)return await m(e,i),i}}async function m(e,t){const n=T(e),i=(await k()).transaction(u,"readwrite");return await i.objectStore(u).put(t,n),await i.done,t}async function se(e){const t=T(e),o=(await k()).transaction(u,"readwrite");await o.objectStore(u).delete(t),await o.done}function T({appConfig:e}){return e.appId}/**
 * @license
 * Copyright 2017 Google LLC
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
 */const ce={"missing-app-config-values":'Missing App configuration value: "{$valueName}"',"only-available-in-window":"This method is available in a Window context.","only-available-in-sw":"This method is available in a service worker context.","permission-default":"The notification permission was not granted and dismissed instead.","permission-blocked":"The notification permission was not granted and blocked instead.","unsupported-browser":"This browser doesn't support the API's required to use the Firebase SDK.","indexed-db-unsupported":"This browser doesn't support indexedDb.open() (ex. Safari iFrame, Firefox Private Browsing, etc)","failed-service-worker-registration":"We are unable to register the default service worker. {$browserErrorMessage}","token-subscribe-failed":"A problem occurred while subscribing the user to FCM: {$errorInfo}","token-subscribe-no-token":"FCM returned no token when subscribing the user to push.","token-unsubscribe-failed":"A problem occurred while unsubscribing the user from FCM: {$errorInfo}","token-update-failed":"A problem occurred while updating the user from FCM: {$errorInfo}","token-update-no-token":"FCM returned no token when updating the user to push.","use-sw-after-get-token":"The useServiceWorker() method may only be called once and must be called before calling getToken() to ensure your service worker is used.","invalid-sw-registration":"The input to useServiceWorker() must be a ServiceWorkerRegistration.","invalid-bg-handler":"The input to setBackgroundMessageHandler() must be a function.","invalid-vapid-key":"The public VAPID key must be a string.","use-vapid-key-after-get-token":"The usePublicVapidKey() method may only be called once and must be called before calling getToken() to ensure your VAPID key is used."},c=new V("messaging","Messaging",ce);/**
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
 */async function de(e,t){const n=await _(e),o=B(t),i={method:"POST",headers:n,body:JSON.stringify(o)};let r;try{r=await(await fetch(S(e.appConfig),i)).json()}catch(s){throw c.create("token-subscribe-failed",{errorInfo:s==null?void 0:s.toString()})}if(r.error){const s=r.error.message;throw c.create("token-subscribe-failed",{errorInfo:s})}if(!r.token)throw c.create("token-subscribe-no-token");return r.token}async function ue(e,t){const n=await _(e),o=B(t.subscriptionOptions),i={method:"PATCH",headers:n,body:JSON.stringify(o)};let r;try{r=await(await fetch(`${S(e.appConfig)}/${t.token}`,i)).json()}catch(s){throw c.create("token-update-failed",{errorInfo:s==null?void 0:s.toString()})}if(r.error){const s=r.error.message;throw c.create("token-update-failed",{errorInfo:s})}if(!r.token)throw c.create("token-update-no-token");return r.token}async function x(e,t){const o={method:"DELETE",headers:await _(e)};try{const r=await(await fetch(`${S(e.appConfig)}/${t}`,o)).json();if(r.error){const s=r.error.message;throw c.create("token-unsubscribe-failed",{errorInfo:s})}}catch(i){throw c.create("token-unsubscribe-failed",{errorInfo:i==null?void 0:i.toString()})}}function S({projectId:e}){return`${Q}/projects/${e}/registrations`}async function _({appConfig:e,installations:t}){const n=await t.getToken();return new Headers({"Content-Type":"application/json",Accept:"application/json","x-goog-api-key":e.apiKey,"x-goog-firebase-installations-auth":`FIS ${n}`})}function B({p256dh:e,auth:t,endpoint:n,vapidKey:o}){const i={web:{endpoint:n,auth:t,p256dh:e}};return o!==j&&(i.web.applicationPubKey=o),i}/**
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
 */const pe=10080*60*1e3;async function fe(e){const t=await ge(e.swRegistration,e.vapidKey),n={vapidKey:e.vapidKey,swScope:e.swRegistration.scope,endpoint:t.endpoint,auth:d(t.getKey("auth")),p256dh:d(t.getKey("p256dh"))},o=await L(e.firebaseDependencies);if(o){if(be(o.subscriptionOptions,n))return Date.now()>=o.createTime+pe?we(e,{token:o.token,createTime:Date.now(),subscriptionOptions:n}):o.token;try{await x(e.firebaseDependencies,o.token)}catch(i){console.warn(i)}return N(e.firebaseDependencies,n)}else return N(e.firebaseDependencies,n)}async function le(e){const t=await L(e.firebaseDependencies);t&&(await x(e.firebaseDependencies,t.token),await se(e.firebaseDependencies));const n=await e.swRegistration.pushManager.getSubscription();return n?n.unsubscribe():!0}async function we(e,t){try{const n=await ue(e.firebaseDependencies,t),o=Object.assign(Object.assign({},t),{token:n,createTime:Date.now()});return await m(e.firebaseDependencies,o),n}catch(n){throw n}}async function N(e,t){const o={token:await de(e,t),createTime:Date.now(),subscriptionOptions:t};return await m(e,o),o.token}async function ge(e,t){const n=await e.pushManager.getSubscription();return n||e.pushManager.subscribe({userVisibleOnly:!0,applicationServerKey:te(t)})}function be(e,t){const n=t.vapidKey===e.vapidKey,o=t.endpoint===e.endpoint,i=t.auth===e.auth,r=t.p256dh===e.p256dh;return n&&o&&i&&r}/**
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
 */function C(e){const t={from:e.from,collapseKey:e.collapse_key,messageId:e.fcmMessageId};return he(t,e),ye(t,e),ve(t,e),t}function he(e,t){if(!t.notification)return;e.notification={};const n=t.notification.title;n&&(e.notification.title=n);const o=t.notification.body;o&&(e.notification.body=o);const i=t.notification.image;i&&(e.notification.image=i);const r=t.notification.icon;r&&(e.notification.icon=r)}function ye(e,t){t.data&&(e.data=t.data)}function ve(e,t){var n,o,i,r,s;if(!t.fcmOptions&&!(!((n=t.notification)===null||n===void 0)&&n.click_action))return;e.fcmOptions={};const p=(i=(o=t.fcmOptions)===null||o===void 0?void 0:o.link)!==null&&i!==void 0?i:(r=t.notification)===null||r===void 0?void 0:r.click_action;p&&(e.fcmOptions.link=p);const f=(s=t.fcmOptions)===null||s===void 0?void 0:s.analytics_label;f&&(e.fcmOptions.analyticsLabel=f)}/**
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
 */function ke(e){return typeof e=="object"&&!!e&&F in e}/**
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
 */function me(e){if(!e||!e.options)throw v("App Configuration Object");if(!e.name)throw v("App Name");const t=["projectId","apiKey","appId","messagingSenderId"],{options:n}=e;for(const o of t)if(!n[o])throw v(o);return{appName:e.name,projectId:n.projectId,apiKey:n.apiKey,appId:n.appId,senderId:n.messagingSenderId}}function v(e){return c.create("missing-app-config-values",{valueName:e})}/**
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
 */class Te{constructor(t,n,o){this.deliveryMetricsExportedToBigQueryEnabled=!1,this.onBackgroundMessageHandler=null,this.onMessageHandler=null,this.logEvents=[],this.isLogServiceStarted=!1;const i=me(t);this.firebaseDependencies={app:t,appConfig:i,installations:n,analyticsProvider:o}}_delete(){return Promise.resolve()}}/**
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
 */async function W(e){try{e.swRegistration=await navigator.serviceWorker.register(J,{scope:z}),e.swRegistration.update().catch(()=>{}),await Se(e.swRegistration)}catch(t){throw c.create("failed-service-worker-registration",{browserErrorMessage:t==null?void 0:t.message})}}async function Se(e){return new Promise((t,n)=>{const o=setTimeout(()=>n(new Error(`Service worker not registered after ${D} ms`)),D),i=e.installing||e.waiting;e.active?(clearTimeout(o),t()):i?i.onstatechange=r=>{var s;((s=r.target)===null||s===void 0?void 0:s.state)==="activated"&&(i.onstatechange=null,clearTimeout(o),t())}:(clearTimeout(o),n(new Error("No incoming service worker found.")))})}/**
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
 */async function _e(e,t){if(!t&&!e.swRegistration&&await W(e),!(!t&&e.swRegistration)){if(!(t instanceof ServiceWorkerRegistration))throw c.create("invalid-sw-registration");e.swRegistration=t}}/**
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
 */async function Ee(e,t){t?e.vapidKey=t:e.vapidKey||(e.vapidKey=j)}/**
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
 */async function H(e,t){if(!navigator)throw c.create("only-available-in-window");if(Notification.permission==="default"&&await Notification.requestPermission(),Notification.permission!=="granted")throw c.create("permission-blocked");return await Ee(e,t==null?void 0:t.vapidKey),await _e(e,t==null?void 0:t.serviceWorkerRegistration),fe(e)}/**
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
 */async function Ie(e,t,n){const o=Ae(t);(await e.firebaseDependencies.analyticsProvider.get()).logEvent(o,{message_id:n[F],message_name:n[X],message_time:n[Z],message_device_time:Math.floor(Date.now()/1e3)})}function Ae(e){switch(e){case l.NOTIFICATION_CLICKED:return"notification_open";case l.PUSH_RECEIVED:return"notification_foreground";default:throw new Error}}/**
 * @license
 * Copyright 2017 Google LLC
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
 */async function De(e,t){const n=t.data;if(!n.isFirebaseMessaging)return;e.onMessageHandler&&n.messageType===l.PUSH_RECEIVED&&(typeof e.onMessageHandler=="function"?e.onMessageHandler(C(n)):e.onMessageHandler.next(C(n)));const o=n.data;ke(o)&&o[ee]==="1"&&await Ie(e,n.messageType,o)}const K="@firebase/messaging",P="0.12.22";/**
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
 */const Oe=e=>{const t=new Te(e.getProvider("app").getImmediate(),e.getProvider("installations-internal").getImmediate(),e.getProvider("analytics-internal"));return navigator.serviceWorker.addEventListener("message",n=>De(t,n)),t},Me=e=>{const t=e.getProvider("messaging").getImmediate();return{getToken:o=>H(t,o)}};function Ne(){E(new I("messaging",Oe,"PUBLIC")),E(new I("messaging-internal",Me,"PRIVATE")),A(K,P),A(K,P,"esm2017")}/**
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
 */async function Ce(){try{await q()}catch{return!1}return typeof window<"u"&&G()&&Y()&&"serviceWorker"in navigator&&"PushManager"in window&&"Notification"in window&&"fetch"in window&&ServiceWorkerRegistration.prototype.hasOwnProperty("showNotification")&&PushSubscription.prototype.hasOwnProperty("getKey")}/**
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
 */async function Ke(e){if(!navigator)throw c.create("only-available-in-window");return e.swRegistration||await W(e),le(e)}/**
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
 */function Pe(e,t){if(!navigator)throw c.create("only-available-in-window");return e.onMessageHandler=t,()=>{e.onMessageHandler=null}}/**
 * @license
 * Copyright 2017 Google LLC
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
 */function Fe(e=$()){return Ce().then(t=>{if(!t)throw c.create("unsupported-browser")},t=>{throw c.create("indexed-db-unsupported")}),U(g(e),"messaging").getImmediate()}async function Le(e,t){return e=g(e),H(e,t)}function xe(e){return e=g(e),Ke(e)}function Be(e,t){return e=g(e),Pe(e,t)}Ne();export{xe as deleteToken,Fe as getMessaging,Le as getToken,Ce as isSupported,Be as onMessage};
