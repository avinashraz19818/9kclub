const currentDomain = self.location.origin;
const availableDomains = [];
const currentScope = '/';
const apiUrl = 'api/Home/HeartBeat';

const logs = {
    "0001": "Checking domain availability..., current domain: {0}",
    "0002": "Heartbeat lost. Last heartbeat was {0} seconds ago.",
    "0003": "Current domain {0} is still available",
    "0004": "try to switch to new domain...",
    "0005": "switchToNewDomain",
    "0006": "Switching to new domain: {0}",
    "0007": "No available domain found",
    "0008": "Activating fallback mode with {0} clients",
    "0009": "Switching from {0} to {1}",
    "0010": "Already on domain {0}, no need to switch.",
    "0011": "Checking client: {0}",
    "0012": "Navigated client to {0}",
    "0013": "Failed to navigate client: {0}",
    "0014": "Finished switching to {0}",
    "0015": "Error updating manifest:",
    "0016": "Fetching failed:, url: {0}",
    "0017": "Error fetching domain list:{0}",
    "0018": "domain list:{0}",
    "0019": "switchMessage error as did not have any clients or available domains",
    "0020": "indexedDB is not exist, before create it need install our app first",
    "0021": "activate after caches.open",
    "0022": "get newUrl & params with {0}",
    "0023": "navigate to online.html, newUrl: {0}",
    "0024": "openWindow to online.html as not support navigate, newUrl: {0}",
    "0025": "activate init worker",
    "0026": "createDynamicOnlinePage",
    "0027": "User is offline",
};

// 创建统一的日志函数
const logger = (level, key, ...args) => {
    if (logs[key]) {
        const message = logs[key].replace(/\{(\d+)\}/g, (match, index) => args[index] || '');
        console[level](`[${key}] ${message}`);
    }
};

// 使用统一的日志函数
const log = (key, ...args) => logger('log', key, ...args);
const error = (key, ...args) => logger('error', key, ...args);


async function getKeyFromDb(id, resolve, reject) {
    const store = await openDb();
    const getRequest = store.get(id);
    getRequest.onsuccess = () => resolve([[id], getRequest.result]);
    getRequest.onerror = (e) => reject(e.target.error);
}

async function setKeyToDb(id, value) {
    console.log("🚀 setKeyToDb", id, value);
    return new Promise((resolve, reject) => {
        openDb().then((store) => {
            const putRequest = store.put(value, id);
            putRequest.onsuccess = () => resolve(true);
            putRequest.onerror = (event) => reject(event.target.error);
        });
    });
}

async function openDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open("_astorage", 2);
        request.onerror = (event) => reject(event.target.error);
        request.onsuccess = (event) => {
            const db = event.target.result;
            const transaction = db.transaction(["renkv"], "readwrite");
            const store = transaction.objectStore("renkv");
            resolve(store);
        };
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains("renkv")) {
                db.createObjectStore("renkv");
            }
        };
    });
}

async function useStore(list) { // 批量从 indexedDB 中获取数据
    const res = await Promise.all(list.map((id) => new Promise((resolve, reject) => getKeyFromDb(id, resolve, reject))));
    try {
        return Object.fromEntries(res);
    } catch (e) { // 兼容性处理
        const obj = {};
        res.forEach(([key, value]) => {
            obj[key] = value;
        });
        return obj;
    }

}
function setParamsToUrlParamsarams(params) {
    try {
        return Object.entries(params).map(([key, value]) => `${key}=${value}`).join('&');
    } catch (e) { // 兼容性处理
        const str = '';
        for (const key in params) {
            str += `${key}=${params[key]}&`;
        }
        return str;
    }
}
