(function registerYtdDownloadProgressUi() {
  const TERMINAL_STATUSES = new Set(['completed', 'failed', 'cancelled', 'skipped']);

  function getDownloadProgressPresentation(status, progressPercent) {
    if (TERMINAL_STATUSES.has(status)) {
      return {
        isIndeterminate: false,
        widthPercent: status === 'completed' ? 100 : 0,
      };
    }

    if (
      typeof progressPercent !== 'number' ||
      status === 'starting' ||
      status === 'cancelling'
    ) {
      return {
        isIndeterminate: true,
        widthPercent: 38,
      };
    }

    return {
      isIndeterminate: false,
      widthPercent: Math.max(0, Math.min(100, progressPercent)),
    };
  }

  globalThis.YtdDownloadProgressUi = {
    getDownloadProgressPresentation,
  };
})();
