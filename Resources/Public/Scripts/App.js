document.addEventListener('DOMContentLoaded', function() {
    if (!window.MiniSearch) {
        console.error('MiniSearch is not available. Exiting');
        return;
    }

    const searchBox = document.getElementById('logs-search-box');
    const resultsContainer = document.getElementById('logs-search-results');

    if (!searchBox) {
        return;
    }

    function initializeSearch(searchInstance) {
        const exceptionDetailsUriTemplate = searchBox.dataset.exceptionDetailsUriTemplate;
        searchBox.disabled = false;

        searchBox.addEventListener('input', (e) => {
            const { value } = e.target;
            const results = searchInstance.search(value, { }).sort((a, b) => {
                return new Date(b.date) - new Date(a.date);
            });

            resultsContainer.innerHTML = '';

            if (results.length === 0) {
                resultsContainer.innerHTML = '<li>No results found</li>';
            } else {
                results.forEach(result => {
                    // Create a linked list item for each result
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
        const exceptionsDataElement = document.getElementById('exceptions-data');
        if (!exceptionsDataElement) {
            console.error('No exceptions data found. Exiting');
            return;
        }

        const exceptions = JSON.parse(exceptionsDataElement.innerText);

        if (!exceptions || !Array.isArray(exceptions)) {
            console.error('No exceptions found. Exiting');
            return;
        }

        // TODO: Also search in duplicates keys
        const searchInstance = new window.MiniSearch({
            idField: 'identifier',
            fields: ['identifier', 'date', 'excerpt', 'duplicates'],
            storeFields: ['identifier', 'date', 'excerpt'],
            searchOptions: {
                prefix: true,
                fuzzy: 0.2,
            }
        });

        searchInstance
        .addAllAsync(exceptions.map(({ identifier, date, excerpt, duplicates }) => {
            return {
                identifier: identifier,
                date: date,
                excerpt: excerpt,
                duplicates: Object.keys(duplicates),
            };
        }))
        .then(() => {
            console.info(`Indexing of ${exceptions.length} items complete, search enabled.`);
            initializeSearch(searchInstance);
        });
    } catch (error) {
        console.error('Error parsing exceptions', error);
    }
});
