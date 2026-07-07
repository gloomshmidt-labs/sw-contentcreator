Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'content',
    key: 'content_creator',
    roles: {
        viewer: {
            // product_manufacturer:read: der Hersteller-Filter der Scans braucht
            // Lese-Zugriff — auch für Integrationen (eingezäunter API-Betrieb)
            privileges: ['product:read', 'category:read', 'media:read', 'product_manufacturer:read'],
            dependencies: [],
        },
        editor: {
            privileges: ['content_creator.viewer', 'product:update', 'category:update', 'media:update'],
            dependencies: ['content_creator.viewer'],
        },
    },
});
