const fs = require('fs');
const item = 'buy-me-coffee.php';

fs.readFile(item, 'utf8', (err, data) => {
    if (err) { console.error('Error reading file:', err); return; }
    const result = data.replace(/BUYMECOFFEE_DEVELOPMENT/gi, () => 'BUYMECOFFEE_PRODUCTION');
    fs.writeFile(item, result, 'utf8', (err) => {
        if (err) { console.error('Error writing file:', err); return; }
        console.log('✅  Production asset enqueued in', item);
    });
});
