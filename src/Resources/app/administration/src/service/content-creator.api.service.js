const ApiService = Shopware.Classes.ApiService;

export default class ContentCreatorApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'content-creator') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'contentCreatorApiService';
    }

    testConnection(provider = null) {
        return this.httpClient
            .post('/content-creator/test-connection', { provider }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    generate(payload) {
        return this.httpClient
            .post('/content-creator/generate', payload, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    apply(payload) {
        return this.httpClient
            .post('/content-creator/apply', payload, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    startBatch(payload) {
        return this.httpClient
            .post('/content-creator/batch', payload, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    latestBackup(payload) {
        return this.httpClient
            .post('/content-creator/backup/latest', payload, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    restoreBackup(backupId) {
        return this.httpClient
            .post('/content-creator/backup/restore', { backupId }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    scanLineBreaks(languageId) {
        return this.httpClient
            .post('/content-creator/linebreaks/scan', { languageId }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    fixLineBreaks(categoryId, languageId) {
        return this.httpClient
            .post('/content-creator/linebreaks/fix', { categoryId, languageId }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    batchStatus(jobId) {
        return this.httpClient
            .get(`/content-creator/batch/${jobId}`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    commitBatch(jobId) {
        return this.httpClient
            .post(`/content-creator/batch/${jobId}/commit`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    gaps(payload) {
        return this.httpClient
            .post('/content-creator/gaps', payload, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    mediaRenameScan(payload) {
        return this.httpClient
            .post('/content-creator/media-rename/scan', payload, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    mediaRenameApply(items) {
        return this.httpClient
            .post('/content-creator/media-rename/apply', { items }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    mediaRenameExport() {
        return this.httpClient
            .get('/content-creator/media-rename/export', { headers: this.getBasicHeaders(), responseType: 'text' })
            .then((response) => response.data);
    }

    freshness(payload) {
        return this.httpClient
            .post('/content-creator/freshness', payload, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    cannibalization(payload) {
        return this.httpClient
            .post('/content-creator/cannibalization', payload, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    qualityReport(payload) {
        return this.httpClient
            .post('/content-creator/quality-report', payload, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }
}
