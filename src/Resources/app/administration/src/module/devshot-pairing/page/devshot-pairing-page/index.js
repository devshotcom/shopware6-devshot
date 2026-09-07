import template from './devshot-pairing-page.html.twig';
import './devshot-pairing-page.scss';

Shopware.Component.register('devshot-pairing-page', {
    template,

    data() {
        return {
            loading: false,
            error: '',
            invitation: null,
            pairings: [],
            operations: [],
        };
    },

    computed: {
        requestId() {
            return this.$route.params.requestId || '';
        },
        operationId() {
            return this.$route.params.operationId || '';
        },
        pendingPairings() {
            return this.pairings.filter((item) => item.status === 'pending');
        },
        connectedPairings() {
            return this.pairings.filter((item) => item.status === 'approved');
        },
        pendingOperations() {
            return this.operations.filter((item) => item.status === 'pending');
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.loading = true;
            this.error = '';
            try {
                const response = await Shopware.Application.getContainer('init').httpClient.get('/_action/devshot/pairings');
                this.pairings = response.data.pairings || [];
                this.operations = response.data.operations || [];
            } catch (error) {
                this.error = error.response?.data?.message || this.$tc('devshot-pairing.messages.loadFailed');
            } finally {
                this.loading = false;
            }
        },

        async createInvitation() {
            this.loading = true;
            this.error = '';
            try {
                const response = await Shopware.Application.getContainer('init').httpClient.post('/_action/devshot/pairings/invitation');
                this.invitation = response.data;
                await this.load();
            } catch (error) {
                this.error = error.response?.data?.message || this.$tc('devshot-pairing.messages.invitationFailed');
            } finally {
                this.loading = false;
            }
        },

        async decidePairing(id, decision) {
            await this.decide(`/_action/devshot/pairings/${id}/${decision}`);
        },

        async decideOperation(id, decision) {
            await this.decide(`/_action/devshot/operations/${id}/${decision}`);
        },

        async decide(url) {
            this.loading = true;
            this.error = '';
            try {
                await Shopware.Application.getContainer('init').httpClient.post(url);
                await this.load();
            } catch (error) {
                this.error = error.response?.data?.message || this.$tc('devshot-pairing.messages.decisionFailed');
            } finally {
                this.loading = false;
            }
        },

        highlighted(id, kind) {
            return kind === 'pairing' ? id === this.requestId : id === this.operationId;
        },
    },
});
