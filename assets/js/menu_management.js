const csrf = '<?= $_SESSION['csrf_token'] ?>';

// Ürünler
function loadProducts() {
    $.post('admin/product_handler.php', {action: 'list', csrf}, data => {
        const res = JSON.parse(data);
        let html = '';
        res.forEach(p => {
            html += `<tr>
                <td>${p.id}</td>
                <td>${p.name}</td>
                <td>${p.cat_name || '-'}</td>
                <td>${p.unit_price} ₺</td>
                <td>${p.stock_quantity}</td>
                <td>
                    <button class="btn btn-sm btn-warning edit-product" data-id="${p.id}">Düzenle</button>
                    <button class="btn btn-sm btn-danger delete-product" data-id="${p.id}">Sil</button>
                </td>
            </tr>`;
        });
        $('#productsTable tbody').html(html);
    });
}

// Malzemeler
function loadIngredients() {
    $.post('admin/ingredient_handler.php', {action: 'list', csrf}, data => {
        const res = JSON.parse(data);
        let html = '';
        res.forEach(i => {
            const alert = i.current_qty <= i.min_qty ? 'text-danger' : '';
            const badge = i.current_qty <= i.min_qty ? '<span class="badge bg-danger">Düşük</span>' : '<span class="badge bg-success">Normal</span>';
            html += `<tr>
                <td>${i.id}</td>
                <td>${i.name}</td>
                <td>${i.unit}</td>
                <td class="${alert}">${i.current_qty}</td>
                <td>${i.min_qty}</td>
                <td>${badge}</td>
                <td><button class="btn btn-sm btn-info log-ingredient" data-id="${i.id}">Log</button></td>
            </tr>`;
        });
        $('#ingredientsTable tbody').html(html);
    });
}

// Ürün Seçimi (Reçete)
function loadProductSelect() {
    $.post('admin/product_handler.php', {action: 'list', csrf}, data => {
        const res = JSON.parse(data);
        let opts = '<option value="">-- Ürün Seç --</option>';
        res.forEach(p => opts += `<option value="${p.id}">${p.name}</option>`);
        $('#selectProductForRecipe').html(opts);
    });
}

// Reçete Yükle
$('#loadRecipe').click(() => {
    const pid = $('#selectProductForRecipe').val();
    if (!pid) return;
    $.post('admin/recipe_handler.php', {action: 'get', product_id: pid, csrf}, data => {
        $('#recipeContainer').html(data);
    });
});

$(document).ready(() => {
    loadProducts();
    loadIngredients();
    loadProductSelect();

    $('#menuTabs a').on('shown.bs.tab', e => {
        if (e.target.getAttribute('href') === '#products') loadProducts();
        if (e.target.getAttribute('href') === '#ingredients') loadIngredients();
    });
});
$(document).ready(() => {
    loadProducts();
    loadIngredients();
    loadProductSelect();
});
function addRecipeItem(product_id) {
    $.post('admin/ingredient_handler.php', { action: 'list', csrf: CSRF }, function(ings) {
        let opts = '<option value="">-- Malzeme Seç --</option>';
        ings.forEach(i => opts += `<option value="${i.id}">${i.name} (${i.current_qty} ${i.unit})</option>`);
        
        const html = `
        <div class="border p-3 mb-3 rounded bg-light">
            <div class="row g-2">
                <div class="col-md-6">
                    <select class="form-select recipe-ingredient">${opts}</select>
                </div>
                <div class="col-md-4">
                    <input type="number" step="0.01" class="form-control recipe-qty" placeholder="Miktar">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-success btn-sm w-100" onclick="saveRecipeItem(${product_id})">Ekle</button>
                </div>
            </div>
        </div>`;
        $('#recipeContainer').append(html);
    });
}

function saveRecipeItem(product_id) {
    const $row = $('.recipe-ingredient').last().closest('.border');
    const ing_id = $row.find('.recipe-ingredient').val();
    const qty = $row.find('.recipe-qty').val();
    if (!ing_id || !qty) return alert('Malzeme ve miktar seçin!');

    $.post('admin/recipe_handler.php', {
        action: 'add_item',
        product_id: product_id,
        ingredient_id: ing_id,
        quantity: qty,
        csrf: CSRF
    }, function(r) {
        const d = JSON.parse(r);
        if (d.success) {
            $('#loadRecipe').click();
            $row.remove();
        } else {
            alert(d.message);
        }
    });
}

function deleteRecipeItem(id) {
    if (!confirm('Silmek istediğinizden emin misiniz?')) return;
    $.post('admin/recipe_handler.php', { action: 'delete_item', id: id, csrf: CSRF }, function(r) {
        const d = JSON.parse(r);
        if (d.success) $('#loadRecipe').click();
    });
}