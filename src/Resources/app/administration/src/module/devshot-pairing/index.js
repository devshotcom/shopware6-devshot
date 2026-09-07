import './page/devshot-pairing-page';

Shopware.Module.register('devshot-pairing', {
    type: 'plugin',
    name: 'DevShotPairing',
    title: 'devshot-pairing.general.title',
    description: 'devshot-pairing.general.description',
    color: '#6d5dfc',
    icon: 'regular-shield',
    routes: {
        index: { component: 'devshot-pairing-page', path: 'index' },
        request: { component: 'devshot-pairing-page', path: 'request/:requestId' },
        operation: { component: 'devshot-pairing-page', path: 'operation/:operationId' },
    },
    navigation: [{
        id: 'devshot-pairing',
        label: 'devshot-pairing.general.title',
        color: '#6d5dfc',
        path: 'devshot.pairing.index',
        icon: 'regular-shield',
        parent: 'sw-settings',
        position: 120,
        privilege: 'system_config:read',
    }],
});
