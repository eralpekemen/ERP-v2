<!-- modals/modal_category_delete.php -->
<div class="modal fade" id="modalCategoryDelete" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form id="formCategoryDelete">
                <input type="hidden" name="id">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Kategoriyi Sil</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p><strong><span id="deleteCatName"></span></strong> kategorisini silmek istediğinizden emin misiniz?</p>
                    <p class="text-danger small">Bu kategoriye ait ürünler de etkilenebilir.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-danger">Evet, Sil</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.getElementById('modalCategoryDelete')?.addEventListener('show.bs.modal', (e) => {
        const button = e.relatedTarget;
        const id = button.getAttribute('data-id');
        const name = button.getAttribute('data-name');

        const form = document.getElementById('formCategoryDelete');
        form.querySelector('[name="id"]').value = id;
        document.getElementById('deleteCatName').textContent = name;
    });

    document.getElementById('formCategoryDelete')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const btn = form.querySelector('button[type="submit"]');
        const modal = form.closest('.modal');

        btn.disabled = true;
        btn.innerHTML = 'Siliniyor...';

        const formData = new FormData(form);
        formData.append('action', 'delete_category');
        formData.append('csrf', CSRF);

        try {
            const res = await fetch('admin/product_handler.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                alert('Kategori silindi!');
                bootstrap.Modal.getInstance(modal).hide();
                loadCategoriesForProduct();
                loadProducts();
            } else {
                alert('Hata: ' + data.message);
            }
        } catch (err) {
            alert('Sunucu hatası!');
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'Evet, Sil';
        }
    });
</script>