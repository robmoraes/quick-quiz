const adRefreshEvents = new EventTarget();
const adRefreshEventName = 'quickquiz:ads-refresh';

export function requestAdRefresh(): void {
  adRefreshEvents.dispatchEvent(new Event(adRefreshEventName));
}

export function onAdRefresh(callback: () => void): () => void {
  adRefreshEvents.addEventListener(adRefreshEventName, callback);

  return () => {
    adRefreshEvents.removeEventListener(adRefreshEventName, callback);
  };
}
