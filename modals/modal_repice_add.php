<!-- modals/modal_recipe_add.php -->
<div class="modal fade" id="modalRecipeAdd" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="formRecipeAdd">
                <div class="modal-header">
                    <h5 class="modal-title">Yeni Reçete Oluştur</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Ürün Seç *</label>
                        <select class="form-select" name="product_id" id="recipeProductSelect" required></select>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <h6>Malzemeler</h6>
                        <div id="recipeIngredients">
                            <div class="row align-items-center mb-2 ingredient-row">
                                <div class="col-md-6">
                                    <select class="form-select ingredient-select" required>
                                        <option value="">-- Malzeme Seç --</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <input type="number" step="0.01" class="form-control" placeholder="Miktar" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-danger btn-sm remove-ingredient">×</button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="addIngredientRow">+ Malzeme Ekle</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Reçete Oluştur</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Malzeme listesi yükle
    async function loadIngredientsForRecipe() {
        const selects = document.querySelectorAll('.ingredient-select');
        const data = await postData('admin/ingredient_handler.php', { action: 'list', csrf: CSRF });
        if (!data) return;
        const opts = '<option value="">-- Malzeme Seç --</option>' + 
                     data.map(i => `<option value="${i.id}" data-unit="${i.unit}">${i.name} (${i.unit})</option>`).join('');
        selects.forEach(s => s.innerHTML = opts);
    }

    // Yeni satır ekle
    document.getElementById('addIngredientRow')?.addEventListener('click', () => {
        const container = document.getElementById('recipeIngredients');
        const row = document.createElement('div');
        row.className = 'row align-items-center mb-2 ingredient-row';
        row.innerHTML = `
            <div class="col-md-6">
                <select class="form-select ingredient-select" required>
                    <option value="">-- Malzeme Seç --</option>
                </select>
            </div>
            <div class="col-md-4">
                <input type="number" step="0.01" class="form-control" placeholder="Miktar" required>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-danger btn-sm remove-ingredient">×</button>
            </div>
        `;
        container.appendChild(row);
        loadIngredientsForRecipe();
    });

    // Satır sil
    document.addEventListener('click', e => {
        if (e.target.classList.contains('remove-ingredient')) {
            if (document.querySelectorAll('.ingredient-row').length > 1) {
                e.target.closest('.ingredient-row').remove();
            }
        }
    });

    // Modal açıldığında
    document.getElementById('modalRecipeAdd')?.addEventListener('show.bs.modal', async () => {
        // Ürünleri yükle
        const productSelect = document.getElementById('recipeProductSelect');
        const products = await postData('admin/product_handler.php', { action: 'list', csrf: CSRF });
        productSelect.innerHTML = '<option value="">-- Ürün Seç --</option>' + 
                                  (products?.map(p => `<option value="${p.id}">${p.name}</option>`).join('') || '');

        // Malzemeleri yükle
        loadIngredientsForRecipe();
    });

    // Form gönder
    document.getElementById('formRecipeAdd')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = 'Oluşturuluyor...';

        const product_id = form.querySelector('[name="product_id"]').value;
        const rows = form.querySelectorAll('.ingredient-row');
        const ingredients = [];

        for (const row of rows) {
            const ing_id = row.querySelector('.ingredient-select').value;
            const qty = row.querySelector('input[type="number"]').value;
            if (ing_id && qty) {
                ingredients.push({ ingredient_id: ing_id, quantity: qty });
            }
        }

        if (ingredients.length === 0) {
            alert('En az 1 malzeme ekleyin!');
            btn.disabled = false;
            btn.innerHTML = 'Reçete Oluştur';
            return;
        }

        try {
            const res = await fetch('admin/recipe_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'add',
                    product_id: product_id,
                    ingredients: JSON.stringify(ingredients),
                    csrf: CSRF
                })
            });
            const data = await res.json();

            if (data.success) {
                alert('Reçete oluşturuldu!');
                bootstrap.Modal.getInstance(form.closest('.modal')).hide();
                loadRecipeForProduct(product_id); // reçeteyi yenile
            } else {
                alert('Hata: ' + data.message);
            }
        } catch (err) {
            alert('Sunucu hatası!');
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'Reçete Oluştur';
        }
    });
</script>