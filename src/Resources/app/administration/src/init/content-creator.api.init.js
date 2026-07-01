import ContentCreatorApiService from '../service/content-creator.api.service';

const { Application } = Shopware;

Application.addServiceProvider('contentCreatorApiService', (container) => {
    const initContainer = Application.getContainer('init');

    return new ContentCreatorApiService(initContainer.httpClient, container.loginService);
});
