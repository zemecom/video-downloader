(() => {
const RECENT_DOWNLOADS_PREVIEW_LIMIT = 5;

function normalizeRecentDownloadsPayload(payload) {
  return Array.isArray(payload?.items) ? payload.items : [];
}

function createRecentDownloadsViewModel(items, options = {}) {
  const normalizedItems = Array.isArray(items) ? items.slice() : [];
  const limit =
    Number.isInteger(options.limit) && options.limit > 0 ? options.limit : null;
  const visibleItems =
    limit === null ? normalizedItems : normalizedItems.slice(0, limit);

  return {
    totalCount: normalizedItems.length,
    visibleItems,
    hasHiddenItems:
      limit !== null && normalizedItems.length > visibleItems.length,
  };
}

function getRecentDownloadModeLabel(mode) {
  if (mode === 'audio') return 'Аудио';
  if (mode === 'video-fhd') return 'Видео FHD';
  return 'Видео BEST';
}

function buildRecentDownloadActions(item) {
  const actions = [];

  if (item?.mode === 'video' || item?.mode === 'video-fhd') {
    actions.push({ kind: 'play', label: 'Воспроизвести' });
  }

  actions.push(
    { kind: 'open', label: 'Открыть' },
    { kind: 'delete', label: 'Удалить' }
  );

  return actions;
}

const api = {
  RECENT_DOWNLOADS_PREVIEW_LIMIT,
  buildRecentDownloadActions,
  createRecentDownloadsViewModel,
  getRecentDownloadModeLabel,
  normalizeRecentDownloadsPayload,
};

if (typeof globalThis !== 'undefined') {
  globalThis.YtdRecentDownloadsUi = api;
}
})();