import './init/content-creator.api.init';
import './acl';
import './component/sw-content-creator-test-connection';
import './module/sw-content-creator';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
