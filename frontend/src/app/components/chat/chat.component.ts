import { Component, OnInit, ViewChild, ElementRef, AfterViewChecked } from '@angular/core';
import { ChatService } from '../../services/chat.service';
import { AuthService } from '../../services/auth.service';
import { Router } from '@angular/router';

@Component({
  selector: 'app-chat',
  templateUrl: './chat.component.html',
  styleUrls: ['./chat.component.css']
})
export class ChatComponent implements OnInit, AfterViewChecked {
  conversations: any[] = [];
  selectedConversation: any = null;
  messages: any[] = [];
  newMessage = '';
  currentUser: any;
  
  showNewConversationModal = false;
  showNewGroupModal = false;
  
  newConversationUserId = '';
  newGroupName = '';
  newGroupMembers = '';

  @ViewChild('scrollMe') private myScrollContainer!: ElementRef;

  constructor(
    private chatService: ChatService,
    private authService: AuthService,
    private router: Router
  ) {
    this.currentUser = this.authService.currentUserValue;
  }

  ngOnInit() {
    this.loadConversations();
    // Poll for new messages every 5 seconds
    setInterval(() => {
      if (this.selectedConversation) {
        this.loadMessages(this.selectedConversation.conversation_id);
      }
      this.loadConversations();
    }, 5000);
  }

  ngAfterViewChecked() {
    this.scrollToBottom();
  }

  scrollToBottom(): void {
    try {
      this.myScrollContainer.nativeElement.scrollTop = this.myScrollContainer.nativeElement.scrollHeight;
    } catch(err) { }
  }

  loadConversations() {
    this.chatService.getConversations().subscribe(response => {
      if (response.success) {
        this.conversations = response.conversations;
      }
    });
  }

  selectConversation(conversation: any) {
    this.selectedConversation = conversation;
    this.loadMessages(conversation.conversation_id);
  }

  loadMessages(conversationId: string) {
    this.chatService.getMessages(conversationId).subscribe(response => {
      if (response.success) {
        this.messages = response.messages.reverse(); // Show oldest first
      }
    });
  }

  sendMessage() {
    if (!this.newMessage.trim() || !this.selectedConversation) return;

    this.chatService.sendMessage(this.selectedConversation.conversation_id, this.newMessage)
      .subscribe(response => {
        if (response.success) {
          this.newMessage = '';
          this.loadMessages(this.selectedConversation.conversation_id);
        }
      });
  }

  logout() {
    this.authService.logout();
    this.router.navigate(['/login']);
  }

  startPrivateConversation() {
    if (!this.newConversationUserId) return;
    
    this.chatService.createPrivateConversation(this.newConversationUserId)
      .subscribe(response => {
        if (response.success) {
          this.showNewConversationModal = false;
          this.newConversationUserId = '';
          this.loadConversations();
          // Select the new conversation
          // Ideally we would find it in the list, but for now just reload
        }
      });
  }

  createGroup() {
    if (!this.newGroupName || !this.newGroupMembers) return;
    
    const members = this.newGroupMembers.split(',').map(id => id.trim());
    
    this.chatService.createGroupConversation(this.newGroupName, members)
      .subscribe(response => {
        if (response.success) {
          this.showNewGroupModal = false;
          this.newGroupName = '';
          this.newGroupMembers = '';
          this.loadConversations();
        }
      });
  }
}
