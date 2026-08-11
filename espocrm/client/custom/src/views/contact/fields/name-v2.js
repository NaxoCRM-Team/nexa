define('custom:views/contact/fields/name-v2', ['views/fields/person-name'], Dep => {
    /**
     * Nexa Contact forms use first and last name only. The underlying
     * salutation value remains readable for imported legacy records.
     */
    return class extends Dep {
        editTemplate = 'custom:contact/fields/name/edit-v2';
    };
});
