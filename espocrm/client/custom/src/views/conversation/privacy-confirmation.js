define(['views/modal'],Base=>class extends Base {
    template='custom:conversation/privacy-confirmation';
    cssName='nexa-delete-confirmation-modal';className='dialog nexa-delete-dialog';noFullHeight=true;
    setup(){this.permanent=Boolean(this.options.permanent);this.headerText=this.permanent?'Permanently erase chat?':'Delete chat?';this.addButton({name:'confirm',label:this.permanent?'Erase permanently':'Delete',style:'danger',disabled:true});this.addButton({name:'cancel',label:'Cancel'});}
    data(){return {permanent:this.permanent};}
    valid(){return this.element.querySelector('[name="confirmation"]').value===(this.permanent?'DELETE':'1')&&(!this.permanent||this.element.querySelector('[name="reason"]').value.trim().length>=10);}
    afterRender(){super.afterRender();this.element.querySelectorAll('input,textarea').forEach(input=>input.addEventListener('input',()=>this.valid()?this.enableButton('confirm'):this.disableButton('confirm')));this.element.querySelector('input').focus();}
    actionConfirm(){if(!this.valid())return;this.trigger('confirm',{confirmation:this.element.querySelector('[name="confirmation"]').value,reason:this.element.querySelector('[name="reason"]')?.value||''});this.close();}
});
