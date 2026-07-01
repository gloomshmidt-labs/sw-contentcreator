Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'content',
    key: 'content_creator',
    roles: {
        viewer: {
            privileges: ['product:read', 'category:read', 'media:read'],
            dependencies: [],
        },
        editor: {
            privileges: ['content_creator.viewer', 'product:update', 'category:update', 'media:update'],
            dependencies: ['content_creator.viewer'],
        },
    },
});
