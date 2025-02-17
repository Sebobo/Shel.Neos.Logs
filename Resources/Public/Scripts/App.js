document.addEventListener('DOMContentLoaded', function() {
    console.debug('Shel.Neos.Logs: App.js loaded');

    if (!window.MiniSearch) {
        console.error('MiniSearch is not available. Exiting');
        return;
    }

    const searchBox = document.getElementById('logs-search-box');
    const resultsContainer = document.getElementById('logs-search-results');

    if (!searchBox) {
        console.error('Search box not found. Exiting');
        return;
    }

    function initializeSearch(searchInstance) {
        const exceptionDetailsUriTemplate = searchBox.dataset.exceptionDetailsUriTemplate;

        searchBox.addEventListener('input', (e) => {
            const { value } = e.target;
            const results = searchInstance.search(value, { }).sort((a, b) => {
                return new Date(b.date) - new Date(a.date);
            });

            console.debug('Searching for', value, results);

            resultsContainer.innerHTML = '';

            if (results.length === 0) {
                resultsContainer.innerHTML = '<p>No results found</p>';
            } else {
                results.forEach(result => {
                    const resultElement = document.createElement('li');
                    const date = new Date(result.date);
                    const detailsUri = exceptionDetailsUriTemplate.replace('__IDENTIFIER__', result.identifier);
                    resultElement.innerHTML = `
                        <a href="${detailsUri}" title="Show exception" target="_blank">
                            ${result.identifier}
                        </a> <time>(${date.toLocaleString()})</time>
                        <p>${result.excerpt}</p>
                    `;
                    resultsContainer.appendChild(resultElement);
                });
            }
        });
    }

    try {
        const exceptions = JSON.parse(searchBox.dataset.exceptions);

        if (!exceptions || !Array.isArray(exceptions)) {
            console.error('No exceptions found. Exiting');
            return;
        }

        console.debug('Exceptions found', exceptions);

        const searchInstance = new window.MiniSearch({
            idField: 'identifier',
            fields: ['identifier', 'date', 'excerpt'],
            storeFields: ['identifier', 'date', 'excerpt'],
            searchOptions: {
                fuzzy: 0.2,
            }
        });

        console.debug('Indexing exceptions');
        searchInstance
        .addAllAsync(exceptions)
        .then(() => {
            console.debug('Indexing complete');
            initializeSearch(searchInstance);
        });
    } catch (error) {
        console.error('Error parsing exceptions', error);
    }
});
