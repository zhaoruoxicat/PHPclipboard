// sw.js — 极简 PWA Service Worker
self.addEventListener('install', event => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

// ⚠️ 不做 fetch 缓存，避免剪切板内容错乱
self.addEventListener('fetch', event => {
  // 直接走网络
});
